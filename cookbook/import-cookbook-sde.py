#!/usr/bin/env python3
"""Import CCP's current JSONL SDE manufacturing recipes into SeAT's existing MariaDB."""

from __future__ import annotations

import json
import os
import subprocess
import sys
import tempfile
import urllib.request
import zipfile
from pathlib import Path

REPO = Path(__file__).resolve().parents[1]
COMPOSE_DIR = Path("/opt/seat-docker")
LATEST_SDE_URL = "https://developers.eveonline.com/static-data/eve-online-static-data-latest-jsonl.zip"
BATCH_SIZE = 1000


def read_dotenv(path: Path) -> dict[str, str]:
    values: dict[str, str] = {}
    if not path.is_file():
        return values
    for raw in path.read_text().splitlines():
        line = raw.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        key, value = line.split("=", 1)
        value = value.strip()
        if len(value) >= 2 and value[0] == value[-1] and value[0] in ("'", '"'):
            value = value[1:-1]
        values[key.strip()] = value
    return values


def sql_rows(table: str, columns: tuple[str, ...], rows: list[tuple[int, ...]]) -> str:
    if not rows:
        return ""
    values = ",\n".join("(" + ",".join(str(int(v)) for v in row) + ")" for row in rows)
    return (
        f"INSERT INTO {table} ({','.join(columns)}) VALUES\n{values}\n"
        f"ON DUPLICATE KEY UPDATE quantity=VALUES(quantity);\n"
    )


def main() -> int:
    if os.geteuid() != 0:
        print("ERROR: run this importer with sudo so it can read SeAT .env and access Docker.", file=sys.stderr)
        return 1

    if not (COMPOSE_DIR / "docker-compose.yml").is_file():
        print(f"ERROR: expected Compose file not found: {COMPOSE_DIR / 'docker-compose.yml'}", file=sys.stderr)
        return 1

    env = read_dotenv(COMPOSE_DIR / ".env")
    db_name = env.get("DB_DATABASE") or env.get("MARIADB_DATABASE") or env.get("MYSQL_DATABASE")
    db_user = env.get("DB_USERNAME") or env.get("MARIADB_USER") or env.get("MYSQL_USER")
    db_password = env.get("DB_PASSWORD") or env.get("MARIADB_PASSWORD") or env.get("MYSQL_PASSWORD")
    if not db_name or not db_user or not db_password:
        print("ERROR: could not read DB_DATABASE, DB_USERNAME and DB_PASSWORD from /opt/seat-docker/.env", file=sys.stderr)
        return 1

    print("Downloading CCP's latest JSONL SDE...")
    request = urllib.request.Request(LATEST_SDE_URL, headers={"User-Agent": "TriglavianCookbook-SDE-Importer/1.0"})
    with tempfile.TemporaryDirectory(prefix="cookbook-sde-") as temp_dir:
        archive_path = Path(temp_dir) / "sde.zip"
        with urllib.request.urlopen(request, timeout=120) as response, archive_path.open("wb") as output:
            while True:
                chunk = response.read(1024 * 1024)
                if not chunk:
                    break
                output.write(chunk)

        with zipfile.ZipFile(archive_path) as archive:
            candidates = [name for name in archive.namelist() if name == "blueprints.jsonl" or name.endswith("/blueprints.jsonl")]
            if len(candidates) != 1:
                raise RuntimeError(f"Expected one blueprints.jsonl in CCP SDE archive; found {len(candidates)}")
            materials: list[tuple[int, int, int, int]] = []
            products: list[tuple[int, int, int, int]] = []
            with archive.open(candidates[0]) as source:
                for raw in source:
                    if not raw.strip():
                        continue
                    record = json.loads(raw)
                    blueprint_id = int(record.get("_key", 0))
                    manufacturing = (record.get("activities") or {}).get("manufacturing")
                    if not blueprint_id or not isinstance(manufacturing, dict):
                        continue
                    for item in manufacturing.get("materials") or []:
                        type_id, quantity = int(item["typeID"]), int(item["quantity"])
                        if type_id > 0 and quantity > 0:
                            materials.append((blueprint_id, 1, type_id, quantity))
                    for item in manufacturing.get("products") or []:
                        type_id, quantity = int(item["typeID"]), int(item["quantity"])
                        if type_id > 0 and quantity > 0:
                            products.append((blueprint_id, 1, type_id, quantity))

        if not materials or not products:
            raise RuntimeError("CCP SDE parsed but no manufacturing materials/products were found; database was not changed.")

        print(f"Parsed {len(materials):,} manufacturing material rows and {len(products):,} product rows.")

        sql = [
            "CREATE TABLE IF NOT EXISTS industryActivityMaterials ("
            "typeID BIGINT UNSIGNED NOT NULL, activityID INT UNSIGNED NOT NULL, "
            "materialTypeID BIGINT UNSIGNED NOT NULL, quantity BIGINT UNSIGNED NOT NULL, "
            "PRIMARY KEY (typeID, activityID, materialTypeID), KEY idx_iam_material (materialTypeID)"
            ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;\n",
            "CREATE TABLE IF NOT EXISTS industryActivityProducts ("
            "typeID BIGINT UNSIGNED NOT NULL, activityID INT UNSIGNED NOT NULL, "
            "productTypeID BIGINT UNSIGNED NOT NULL, quantity BIGINT UNSIGNED NOT NULL, "
            "PRIMARY KEY (typeID, activityID, productTypeID), KEY idx_iap_product (productTypeID, activityID)"
            ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;\n",
            "START TRANSACTION;\n",
            "DELETE FROM industryActivityMaterials WHERE activityID=1;\n",
            "DELETE FROM industryActivityProducts WHERE activityID=1;\n",
        ]
        for offset in range(0, len(materials), BATCH_SIZE):
            sql.append(sql_rows("industryActivityMaterials", ("typeID", "activityID", "materialTypeID", "quantity"), materials[offset:offset + BATCH_SIZE]))
        for offset in range(0, len(products), BATCH_SIZE):
            sql.append(sql_rows("industryActivityProducts", ("typeID", "activityID", "productTypeID", "quantity"), products[offset:offset + BATCH_SIZE]))
        sql.append("COMMIT;\n")
        sql.append(
            "SELECT 'industryActivityMaterials' AS table_name, COUNT(*) AS rows_imported FROM industryActivityMaterials WHERE activityID=1;\n"
            "SELECT 'industryActivityProducts' AS table_name, COUNT(*) AS rows_imported FROM industryActivityProducts WHERE activityID=1;\n"
        )

        # The importer itself runs as root; do not invoke nested sudo here.
        # Nested sudo applies env_reset and silently drops MYSQL_PWD before Compose
        # can forward it into the MariaDB client container.
        command = [
            "docker", "compose", "exec", "-T", "-e", "MYSQL_PWD",
            "mariadb", "mariadb", "--default-character-set=utf8mb4",
            "-u", db_user, db_name,
        ]
        docker_env = os.environ.copy()
        docker_env["MYSQL_PWD"] = db_password
        print("Importing manufacturing recipes into the existing SeAT database...")
        process = subprocess.Popen(command, cwd=COMPOSE_DIR, stdin=subprocess.PIPE, text=True, env=docker_env)
        try:
            assert process.stdin is not None
            process.stdin.write("".join(sql))
            process.stdin.close()
            return_code = process.wait()
        except BaseException:
            process.kill()
            process.wait()
            raise
        if return_code != 0:
            print("ERROR: MariaDB import failed. The import transaction was not committed.", file=sys.stderr)
            return return_code

    print("SDE manufacturing import completed.")
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except Exception as exc:
        print(f"ERROR: {exc}", file=sys.stderr)
        raise SystemExit(1)
