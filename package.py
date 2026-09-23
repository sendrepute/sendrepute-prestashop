"""Build a deterministic, installable PrestaShop module ZIP."""
from pathlib import Path
import sys
import zipfile

root = Path(__file__).resolve().parent
version = sys.argv[1] if len(sys.argv) > 1 else "0.1.0"
if version not in {"0.1.0", "0.1.1"}:
    raise SystemExit("package version must be 0.1.0 or 0.1.1")
target = Path(sys.argv[2]) if len(sys.argv) > 2 else root / "dist" / f"sendrepute-prestashop-{version}.zip"
target.parent.mkdir(exist_ok=True)
with zipfile.ZipFile(target, "w", zipfile.ZIP_DEFLATED) as archive:
    for file in sorted((root / "sendrepute").rglob("*")):
        if file.is_file() and file.suffix in {".php", ".tpl", ".js", ".css"}:
            info = zipfile.ZipInfo(file.relative_to(root).as_posix(), (2026, 1, 1, 0, 0, 0))
            info.external_attr = 0o644 << 16
            info.compress_type = zipfile.ZIP_DEFLATED
            contents = file.read_bytes()
            if file.name == "sendrepute.php" and version != "0.1.0":
                contents = contents.replace(b"$this->version = '0.1.0';", f"$this->version = '{version}';".encode(), 1)
            archive.writestr(info, contents)
try:
    print(target.relative_to(Path.cwd()))
except ValueError:
    print(target)