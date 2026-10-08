"""WordPress の実行時ファイルだけを、安全に配布 ZIP にまとめる。"""

import argparse
import os
from pathlib import Path
import re
import sys
import tempfile
import zipfile


SLUG = "od-visual-regression"
ROOT = Path(__file__).resolve().parent.parent
EXCLUDED_DIRS = {
    "node_modules", "vendor", "src", "tests", "test", "coverage",
    "runner", "dispatcher", "apps", "packages", "docs",
}
ASSET_EXTENSIONS = {
    ".js", ".css", ".php", ".png", ".jpg", ".jpeg", ".gif", ".svg",
    ".webp", ".avif", ".ico", ".woff", ".woff2", ".ttf", ".eot",
}


def excluded(path):
    """隠しファイル、開発用ファイル、認証情報の候補を除外する。"""
    return any(
        part.startswith(".") or part.lower() in EXCLUDED_DIRS
        or re.search(r"(^|[-_.])(secret|secrets|credentials|token|tokens|env)([-_.]|$)", part, re.I)
        for part in path.parts
    )


def distributable(path):
    if excluded(path):
        return False
    if len(path.parts) == 1:
        return path.name in {f"{SLUG}.php", "uninstall.php", "readme.txt", "LICENSE", "LICENSE.txt"}
    if path.parts[0] == "languages":
        return path.suffix.lower() in {".po", ".mo", ".pot", ".json"}
    if path.parts[0] in {"includes", "rest", "admin"} and path.suffix == ".php":
        return True
    if path.parts[0] == "assets" or path.parts[:2] == ("admin", "build"):
        return path.suffix.lower() in ASSET_EXTENSIONS
    return False


def build(tag):
    plugin = ROOT / "wordpress" / SLUG
    entry = plugin / f"{SLUG}.php"
    if plugin.is_symlink() or not entry.is_file() or entry.is_symlink():
        raise ValueError("プラグインのエントリーポイントがありません、またはシンボリックリンクです。")
    match = re.search(r"^\s*\*\s*Version:\s*(\S+)\s*$", entry.read_text(), re.M)
    if not match or not re.fullmatch(r"\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?", match[1]):
        raise ValueError("プラグインヘッダーの Version が不正です。")
    version = match[1]
    if tag is not None and tag != f"plugin-v{version}":
        raise ValueError(f"タグ {tag!r} と Version {version} が一致しません。")

    files = []
    for directory, dirs, names in os.walk(plugin, followlinks=False):
        base = Path(directory)
        for name in dirs + names:
            candidate = base / name
            if candidate.is_symlink():
                raise ValueError(f"シンボリックリンクは配布できません: {candidate.relative_to(plugin)}")
        dirs[:] = [name for name in dirs if not excluded((base / name).relative_to(plugin))]
        files.extend(base / name for name in names if distributable((base / name).relative_to(plugin)))

    admin = plugin / "admin"
    required = (admin / "src").exists() or (admin / "package.json").exists()
    required = required or any("admin/build/" in path.read_text() for path in files if path.suffix == ".php")
    required = required or (admin / "build").exists()
    if required:
        scripts = [path for path in files if path.relative_to(plugin).parts[:2] == ("admin", "build") and path.suffix == ".js"]
        if not scripts or any(path.with_suffix(".asset.php") not in files or path.stat().st_size == 0 or path.with_suffix(".asset.php").stat().st_size == 0 for path in scripts):
            raise ValueError("管理画面の build が不足しています。admin/build の JS と対応する .asset.php を生成してください。")

    output_dir = ROOT / "build"
    if output_dir.is_symlink():
        raise ValueError("出力ディレクトリにシンボリックリンクは使用できません。")
    output_dir.mkdir(exist_ok=True)
    output = output_dir / f"{SLUG}.zip"
    temporary = None
    try:
        with tempfile.NamedTemporaryFile(dir=output_dir, suffix=".zip", delete=False) as handle:
            temporary = Path(handle.name)
        with zipfile.ZipFile(temporary, "w", zipfile.ZIP_DEFLATED) as archive:
            for path in sorted(files):
                archive.write(path, str(Path(SLUG) / path.relative_to(plugin)))
        with zipfile.ZipFile(temporary) as archive:
            if archive.testzip() is not None:
                raise ValueError("生成した ZIP の検査に失敗しました。")
        os.replace(temporary, output)
    finally:
        if temporary is not None:
            temporary.unlink(missing_ok=True)
    print(f"生成しました: {output} (Version {version})")
    if not required:
        print("管理画面は未実装です。現在の PHP・翻訳を配布します。")


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--tag", help="プラグイン Version と照合する plugin-v タグ")
    args = parser.parse_args()
    try:
        build(args.tag)
    except (OSError, ValueError, zipfile.BadZipFile) as error:
        print(f"ビルド失敗: {error}", file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(main())
