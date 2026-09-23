<?php

use Symfony\Component\Mailer\Exception\ExceptionInterface;
use PrestaShop\PrestaShop\Core\Module\Exception\ModuleErrorInterface;

final class SendReputeBlockedException extends RuntimeException implements ExceptionInterface, ModuleErrorInterface
{
}