<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/classes/SendReputeClient.php';
require_once __DIR__ . '/classes/SendReputeBlockedException.php';

use Symfony\Component\Mime\Email;

class Sendrepute extends Module
{
    const CFG_ENABLED = 'SENDREPUTE_ENABLED';
    const CFG_CONSENT = 'SENDREPUTE_CONSENT';
    const CFG_TEMPLATES = 'SENDREPUTE_TEMPLATES';
    const CFG_RISK = 'SENDREPUTE_RISK';
    const CFG_THRESHOLD = 'SENDREPUTE_THRESHOLD';
    const CFG_FAILURE = 'SENDREPUTE_FAILURE';
    const CFG_MODEL = 'SENDREPUTE_MODEL';
    const MAX_DISPLAY = 524288;

    private $pendingTemplates = [];
    private $classifierForTesting;

    public function __construct()
    {
        $this->name = 'sendrepute';
        $this->tab = 'emailing';
        $this->version = '0.1.0';
        $this->author = 'SendRepute';
        $this->need_instance = 0;
        $this->bootstrap = true;
        $this->ps_versions_compliancy = ['min' => '9.0.0', 'max' => '9.0.3'];
        parent::__construct();
        $this->displayName = $this->trans('SendRepute mail classification', [], 'Modules.Sendrepute.Admin');
        $this->description = $this->trans('Classifies opted-in, fully rendered Symfony Mailer messages before transport.', [], 'Modules.Sendrepute.Admin');
    }

    public function install()
    {
        return parent::install()
            && $this->registerHook('actionEmailSendBefore')
            && $this->registerHook('actionMailAlterMessageBeforeSend')
            && Configuration::updateValue(self::CFG_ENABLED, 0)
            && Configuration::updateValue(self::CFG_CONSENT, 0)
            && Configuration::updateValue(self::CFG_TEMPLATES, '[]')
            && Configuration::updateValue(self::CFG_RISK, 'advisory')
            && Configuration::updateValue(self::CFG_THRESHOLD, '0.80')
            && Configuration::updateValue(self::CFG_FAILURE, 'open')
            && Configuration::updateValue(self::CFG_MODEL, '');
    }

    public function uninstall()
    {
        foreach ([self::CFG_ENABLED, self::CFG_CONSENT, self::CFG_TEMPLATES, self::CFG_RISK, self::CFG_THRESHOLD, self::CFG_FAILURE, self::CFG_MODEL, 'SENDREPUTE_LAST'] as $key) {
            Configuration::deleteByName($key);
        }
        return parent::uninstall();
    }

    public function hookActionEmailSendBefore($params)
    {
        $selected = null;
        if (!Configuration::get(self::CFG_ENABLED) || !Configuration::get(self::CFG_CONSENT)) {
            $this->pendingTemplates[] = $selected;
            return true;
        }
        $template = isset($params['template']) && is_string($params['template']) ? $params['template'] : '';
        if ($template !== '' && in_array($template, $this->templates(), true)) {
            $selected = $template;
        }
        $this->pendingTemplates[] = $selected;
        return true;
    }

    public function hookActionMailAlterMessageBeforeSend($params)
    {
        $template = $this->pendingTemplates ? array_pop($this->pendingTemplates) : null;
        if ($template === null) {
            return;
        }
        $message = isset($params['message']) ? $params['message'] : null;
        if (!$message instanceof Email) {
            return $this->failure('Final mail object is not a supported Symfony Mime Email.');
        }
        try {
            $subject = $message->getSubject();
            $text = $message->getTextBody();
            $html = $message->getHtmlBody();
            if (!is_string($subject) || $subject === '' || ($text === null && $html === null)
                || ($text !== null && !is_string($text)) || ($html !== null && !is_string($html))) {
                throw new RuntimeException('Displayed message fields are unsupported.');
            }
            $from = $message->getFrom();
            $sender = $from ? trim((string) $from[0]->getName()) : '';
            if ($sender === '') {
                $sender = (string) Configuration::get('PS_SHOP_NAME');
            }
            $model = (string) Configuration::get(self::CFG_MODEL);
            $request = self::classificationRequest($subject, $text, $html, $sender, $model);
            $result = $this->classify($request);
            Configuration::updateValue('SENDREPUTE_LAST', json_encode([
                'label' => $result['label'], 'probability' => $result['probability'], 'at' => time(), 'template' => $template,
            ]));
            $threshold = (float) Configuration::get(self::CFG_THRESHOLD);
            if (Configuration::get(self::CFG_RISK) === 'block' && $result['probability'] >= $threshold) {
                throw new SendReputeBlockedException('SendRepute blocked this opted-in message at the configured risk threshold.');
            }
        } catch (SendReputeBlockedException $e) {
            throw $e;
        } catch (Throwable $e) {
            return $this->failure($e->getMessage());
        }
    }

    /** Separate the paid HTTP boundary from the rendered-message decision. */
    protected function requestClassification(array $request)
    {
        return SendReputeClient::request('POST', '/v1/classify', $request);
    }

    private function failure($reason)
    {
        PrestaShopLogger::addLog('SendRepute analysis failed: ' . substr(preg_replace('/[\\r\\n]/', ' ', (string) $reason), 0, 300), 2);
        if (Configuration::get(self::CFG_FAILURE) === 'closed') {
            throw new SendReputeBlockedException('SendRepute fail-closed policy blocked this opted-in message.');
        }
    }

    private function classify(array $request)
    {
        if ($this->classifierForTesting !== null) {
            return call_user_func($this->classifierForTesting, $request);
        }
        return SendReputeClient::classification($this->requestClassification($request));
    }

    /**
     * Installs an in-process classifier fixture for the isolated CLI integration
     * harness. Web requests can never replace the real HTTPS client.
     */
    public function setCliClassifierForTesting(callable $classifier)
    {
        if (PHP_SAPI !== 'cli') {
            throw new LogicException('Classifier fixtures are restricted to CLI tests.');
        }
        $this->classifierForTesting = $classifier;
    }

    private static function plainToInertText($plain, $forBody = false)
    {
        if (!is_string($plain) || preg_match('/[\\x00\\x0B\\x0C]/', $plain)) {
            throw new RuntimeException('Plain displayed content is unsupported.');
        }
        if ($forBody && (preg_match('/=\\r?\\n/', $plain) || preg_match('/=[0-9A-Fa-f]{2}/', $plain))) {
            // The current server pre-decodes quoted-printable-looking text,
            // including soft line breaks, before removing markup. Refuse this
            // ambiguity rather than classify text different from what is shown.
            throw new RuntimeException('Body contains ambiguous transfer-encoded text.');
        }
        // Preserve literal angle brackets as explicit words. The resulting body
        // contains no tag opener for downstream HTML/style regex normalization.
        $inert = str_replace(['<', '>'], [' [less-than] ', ' [greater-than] '], $plain);
        if ($forBody) {
            // Prevent server MIME-part base64 recognition if displayed text
            // happens to contain a header-like phrase. The punctuation prefix
            // also makes a whole-body base64-looking literal non-decodable.
            $inert = preg_replace(
                '/content-transfer-encoding(\\s*:\\s*base64)/i',
                'content transfer encoding$1',
                $inert
            );
            if (!is_string($inert)) {
                throw new RuntimeException('Body transfer-encoding normalization failed.');
            }
            $inert = '[_] ' . $inert;
        }
        return $inert;
    }

    private static function htmlToInertText($html)
    {
        if (!is_string($html) || !class_exists('DOMDocument') || preg_match('/<(?:style|script|template|svg|math)\\b/i', $html)
            || preg_match('/\\sstyle\\s*=|\\shidden(?:\\s|=|>)/i', $html)) {
            throw new RuntimeException('HTML uses unsupported display semantics.');
        }
        $previous = libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $loaded = $dom->loadHTML('<?xml encoding="UTF-8"><body>' . $html . '</body>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded) {
            throw new RuntimeException('HTML displayed content could not be parsed safely.');
        }
        $body = $dom->getElementsByTagName('body')->item(0);
        if (!$body) {
            throw new RuntimeException('HTML displayed content has no parseable body.');
        }
        $parts = [];
        self::collectVisibleText($body, $parts);
        $text = html_entity_decode(implode(' ', $parts), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\\s+/u', ' ', $text);
        if (!is_string($text)) {
            throw new RuntimeException('HTML displayed content normalization failed.');
        }
        return self::plainToInertText(trim($text), true);
    }

    private static function classificationRequest($subject, $text, $html, $sender, $model)
    {
        $safeSubject = self::plainToInertText($subject);
        $safeSender = self::plainToInertText($sender);
        if (strlen($safeSubject) > 998) {
            throw new RuntimeException('Displayed message exceeds safe classification bounds.');
        }

        if ($text !== null && $html !== null) {
            // Keep the transport Email untouched. The typed API normalizes these
            // alternatives independently, so markup in one cannot consume the
            // other. Plain text remains inert through the legacy normalizer,
            // while original HTML is retained for link/content auditing.
            $safeText = self::plainToInertText($text, true);
            self::validateTypedHtml($html);
            $aggregate = strlen($safeText) + strlen($safeText) + strlen($html);
            if ($aggregate > self::MAX_DISPLAY) {
                throw new RuntimeException('Displayed message exceeds safe classification bounds.');
            }
            $request = [
                'sender' => substr($safeSender, 0, 320),
                'subject' => $safeSubject,
                'body' => $safeText,
                'displayedAlternatives' => [
                    ['contentType' => 'text/plain', 'body' => $safeText],
                    ['contentType' => 'text/html', 'body' => $html],
                ],
            ];
        } else {
            $display = $html !== null ? self::htmlToInertText($html) : self::plainToInertText($text, true);
            if (strlen($display) > self::MAX_DISPLAY) {
                throw new RuntimeException('Displayed message exceeds safe classification bounds.');
            }
            $request = ['sender' => substr($safeSender, 0, 320), 'subject' => $safeSubject, 'body' => $display];
        }

        if (in_array($model, ['thor', 'theos', 'athena', 'odin', 'freya', 'hermes', 'ares', 'apollo'], true)) {
            $request['model'] = $model;
        }
        return $request;
    }

    private static function validateTypedHtml($html)
    {
        if (!is_string($html) || !class_exists('DOMDocument') || preg_match('/[\x00\x0B\x0C]/', $html)) {
            throw new RuntimeException('HTML displayed content is unsupported.');
        }
        if (preg_match('/=\r?\n/', $html) || preg_match('/=[0-9A-Fa-f]{2}/', $html)
            || preg_match('/content-transfer-encoding\s*:\s*base64/i', $html)
            || (strlen(trim($html)) >= 80 && preg_match('/^[A-Za-z0-9+\/=\r\n]+$/D', trim($html)))) {
            throw new RuntimeException('HTML contains ambiguous transfer-encoded text.');
        }
        $previous = libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $loaded = $dom->loadHTML('<?xml encoding="UTF-8"><body>' . $html . '</body>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded || !$dom->getElementsByTagName('body')->item(0)) {
            throw new RuntimeException('HTML displayed content could not be parsed safely.');
        }
    }

    private static function collectVisibleText(DOMNode $node, array &$parts)
    {
        if ($node instanceof DOMText) {
            $parts[] = $node->nodeValue;
            return;
        }
        if (!$node instanceof DOMElement && !$node instanceof DOMDocument) {
            return;
        }
        if ($node instanceof DOMElement) {
            $tag = strtolower($node->tagName);
            if (in_array($tag, ['head', 'script', 'style', 'template', 'noscript', 'svg', 'math'], true)) {
                return;
            }
            if ($tag === 'img') {
                $parts[] = $node->getAttribute('alt');
                $parts[] = '[image source ' . $node->getAttribute('src') . ']';
            }
        }
        foreach ($node->childNodes as $child) {
            self::collectVisibleText($child, $parts);
        }
        if ($node instanceof DOMElement && strtolower($node->tagName) === 'a') {
            $parts[] = '[link destination ' . $node->getAttribute('href') . ']';
        }
        if ($node instanceof DOMElement && in_array(strtolower($node->tagName), ['br', 'p', 'div', 'li', 'tr', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6'], true)) {
            $parts[] = "\n";
        }
    }

    private function templates()
    {
        $decoded = json_decode((string) Configuration::get(self::CFG_TEMPLATES), true);
        return is_array($decoded) ? array_values(array_filter($decoded, function ($v) {
            return is_string($v) && preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $v);
        })) : [];
    }

    public function getContent()
    {
        $notice = '';
        if (Tools::isSubmit('submitSendrepute')) {
            if (!$this->validAdminToken()) {
                return $this->displayError($this->trans('Invalid CSRF token.', [], 'Admin.Notifications.Error'));
            }
            $raw = preg_split('/[\\s,]+/', (string) Tools::getValue('templates', ''));
            $templates = array_values(array_unique(array_filter($raw, function ($v) {
                return preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $v);
            })));
            $threshold = filter_var(Tools::getValue('threshold'), FILTER_VALIDATE_FLOAT);
            $risk = Tools::getValue('risk') === 'block' ? 'block' : 'advisory';
            $failure = Tools::getValue('failure') === 'closed' ? 'closed' : 'open';
            $model = (string) Tools::getValue('model', '');
            $models = ['', 'thor', 'theos', 'athena', 'odin', 'freya', 'hermes', 'ares', 'apollo'];
            Configuration::updateValue(self::CFG_ENABLED, (int) Tools::getValue('enabled'));
            Configuration::updateValue(self::CFG_CONSENT, (int) Tools::getValue('consent'));
            Configuration::updateValue(self::CFG_TEMPLATES, json_encode($templates));
            Configuration::updateValue(self::CFG_RISK, $risk);
            Configuration::updateValue(self::CFG_FAILURE, $failure);
            Configuration::updateValue(self::CFG_THRESHOLD, $threshold !== false && is_finite($threshold) ? max(0, min(1, $threshold)) : 0.8);
            Configuration::updateValue(self::CFG_MODEL, in_array($model, $models, true) ? $model : '');
            $notice = $this->displayConfirmation($this->trans('Settings saved.', [], 'Admin.Notifications.Success'));
        }
        if (Tools::isSubmit('checkSendrepute')) {
            if (!$this->validAdminToken()) {
                return $this->displayError($this->trans('Invalid CSRF token.', [], 'Admin.Notifications.Error'));
            }
            try {
                $account = SendReputeClient::request('GET', '/v1/account');
                SendReputeClient::pricing(SendReputeClient::request('GET', '/v1/pricing'));
                $notice = $this->displayConfirmation(sprintf('Connection verified with non-paid account/pricing calls for %s. Paid classify scope was not probed.', isset($account['username']) ? htmlspecialchars($account['username'], ENT_QUOTES, 'UTF-8') : 'account'));
            } catch (Throwable $e) {
                $notice = $this->displayError('Connection check failed: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
            }
        }
        return $notice . $this->renderForm();
    }

    private function validAdminToken()
    {
        $submitted = (string) Tools::getValue('token', '');
        if (PHP_SAPI !== 'cli') {
            $context = Context::getContext();
            $expected = (string) $context->cookie->sendrepute_config_token;
            return $submitted !== '' && $expected !== '' && hash_equals($expected, $submitted);
        }
        $expected = (string) Tools::getAdminTokenLite('AdminModules');
        return $submitted !== '' && $expected !== '' && hash_equals($expected, $submitted);
    }

    private function adminToken()
    {
        if (PHP_SAPI !== 'cli') {
            $context = Context::getContext();
            $token = bin2hex(random_bytes(32));
            $context->cookie->sendrepute_config_token = $token;
            $context->cookie->write();
            return $token;
        }
        return Tools::getAdminTokenLite('AdminModules');
    }

    private function renderForm()
    {
        $last = json_decode((string) Configuration::get('SENDREPUTE_LAST'), true);
        $helper = new HelperForm();
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->token = $this->adminToken();
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;
        $helper->submit_action = 'submitSendrepute';
        $helper->fields_value = [
            'enabled' => (int) Configuration::get(self::CFG_ENABLED),
            'consent' => (int) Configuration::get(self::CFG_CONSENT),
            'templates' => implode(', ', $this->templates()),
            'risk' => (string) Configuration::get(self::CFG_RISK),
            'failure' => (string) Configuration::get(self::CFG_FAILURE),
            'threshold' => (string) Configuration::get(self::CFG_THRESHOLD),
            'model' => (string) Configuration::get(self::CFG_MODEL),
        ];
        $models = array_map(function ($v) { return ['id' => $v, 'name' => $v === '' ? 'Account default' : ucfirst($v)]; }, ['', 'thor', 'theos', 'athena', 'odin', 'freya', 'hermes', 'ares', 'apollo']);
        $form = [['form' => [
            'legend' => ['title' => 'SendRepute rendered-message policy', 'icon' => 'icon-shield'],
            'description' => 'PrestaShop 9.0.x only. Nothing is analyzed until enabled, paid consent is checked, and exact template names are opted in. Leave password/reset/order templates absent unless explicitly approved. Token and HTTPS API origin come only from SENDREPUTE_API_TOKEN and SENDREPUTE_API_BASE server environment variables.',
            'input' => [
                ['type' => 'switch', 'label' => 'Enable classification', 'name' => 'enabled', 'is_bool' => true, 'values' => [['id' => 'on', 'value' => 1, 'label' => 'Yes'], ['id' => 'off', 'value' => 0, 'label' => 'No']]],
                ['type' => 'switch', 'label' => 'Paid/content consent', 'name' => 'consent', 'desc' => 'Authorize sending the final displayed subject and both displayed bodies to SendRepute and paying for each classification.', 'is_bool' => true, 'values' => [['id' => 'con', 'value' => 1, 'label' => 'I consent'], ['id' => 'nocon', 'value' => 0, 'label' => 'Off']]],
                ['type' => 'text', 'label' => 'Opted-in template names', 'name' => 'templates', 'desc' => 'Comma/space separated exact names. Empty by default. Common critical names such as password, password_query, order_conf, payment, and bankwire are not selected automatically.'],
                ['type' => 'select', 'label' => 'Risk result policy', 'name' => 'risk', 'options' => ['query' => [['id' => 'advisory', 'name' => 'Advisory only (never interrupts for risk)'], ['id' => 'block', 'name' => 'Block at/above threshold']], 'id' => 'id', 'name' => 'name']],
                ['type' => 'text', 'label' => 'Spam probability threshold', 'name' => 'threshold', 'desc' => 'Finite number from 0 through 1. Independent of API failure policy.'],
                ['type' => 'select', 'label' => 'API/validation failure policy', 'name' => 'failure', 'options' => ['query' => [['id' => 'open', 'name' => 'Open — preserve original send'], ['id' => 'closed', 'name' => 'Closed — interrupt original send']], 'id' => 'id', 'name' => 'name']],
                ['type' => 'select', 'label' => 'Classifier model', 'name' => 'model', 'options' => ['query' => $models, 'id' => 'id', 'name' => 'name']],
            ],
            'submit' => ['title' => 'Save settings'],
            'buttons' => [['title' => 'Check connection (non-paid)', 'name' => 'checkSendrepute', 'type' => 'submit', 'class' => 'btn btn-default pull-right', 'icon' => 'process-icon-refresh']],
        ]]];
        $summary = is_array($last) && isset($last['label'], $last['probability'], $last['at'])
            ? '<div class="alert alert-info">Latest advisory (not a delivery result): ' . htmlspecialchars($last['label'], ENT_QUOTES, 'UTF-8') . ', ' . number_format(100 * (float) $last['probability'], 1) . '%; ' . date('c', (int) $last['at']) . '.</div>'
            : '';
        return $summary . $helper->generateForm($form);
    }
}