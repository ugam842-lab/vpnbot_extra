#!/usr/bin/env python3
"""Генератор клиентских профилей IKEv2 (.sswan для Android strongSwan + .mobileconfig для iOS/macOS).

Запускается на хосте vds2 как root. Читает серверные параметры из
/etc/swanctl/conf.d/ikev2.conf-совместимого окружения (фактически из
захардкоженных ниже констант, зеркалящих конфиг) и CA-сертификат из
/etc/swanctl/x509ca/ca.pem.

Вход — JSON на stdin:
{
  "username": "u_<client_id>",
  "password": "<пароль>",
  "name":     "человекочитаемое имя профиля",
  "host":     "2.26.124.62",           # опц., дефолт из конфига
  "remote_id": "2.26.124.62"            # IKE identity сервера (SAN/IP)
}

Выход — JSON на stdout:
{
  "sswan": "<текст .sswan>",
  "mobileconfig": "<текст .mobileconfig>",
  "ca_pem": "<PEM CA>",
  "ios_uuid": "<UUID профиля>"
}
"""

import base64
import json
import os
import sys
import uuid

# --- серверные константы (зеркалируют /etc/swanctl/conf.d/ikev2.conf) ---
DEFAULT_HOST = "2.26.124.62"
DEFAULT_REMOTE_ID = "2.26.124.62"
CA_PATH = os.environ.get("IKV2_CA_PATH", "/etc/swanctl/x509ca/ca.pem")

# Параметры IKE/ESP, согласованные с конфигом сервера.
IKE = "aes256-sha256-modp2048,aes128-sha256-modp2048"
ESP = "aes256-sha256-modp2048,aes128-sha256-modp2048"


def read_ca_pem():
    with open(CA_PATH, "r") as f:
        return f.read().strip()


def make_sswan(cfg):
    """Android strongSwan профиль (.sswan)."""
    # .sswan — это JSON с ключами strongSwan; сервер аутентифицируется по CA.
    return json.dumps(
        {
            "uuid": str(uuid.uuid4()),
            "name": cfg["name"],
            "type": "ikev2-eap",
            "remote": {
                "addr": cfg["host"],
                "id": cfg["remote_id"],
                "eap_id": cfg["username"],
            },
            "local": {
                "eap_id": cfg["username"],
            },
            "ike": IKE,
            "esp": ESP,
            "ika": {},
            "eap": {
                "username": cfg["username"],
                "password": cfg["password"],
            },
            "split_tunneling": {
                "enabled": 0,
                "ipv4_excluded": [],
            },
        },
        indent=2,
    )


def make_mobileconfig(cfg, ca_pem):
    """iOS/macOS профиль (.mobileconfig) — конфигурационный plist."""
    prof_uuid = str(uuid.uuid4()).upper()
    ca_data = base64.b64encode(ca_pem.encode()).decode()
    host = cfg["host"]
    # Имя обратного DNS для IKE-хендшейка iOS использует адрес сервера как есть.
    remote_addr = host

    payload = {
        "PayloadContent": [
            {
                "PayloadType": "com.apple.security.root",
                "PayloadIdentifier": "ugam.ikev2.ca." + str(uuid.uuid4()).upper(),
                "PayloadUUID": str(uuid.uuid4()).upper(),
                "PayloadVersion": 1,
                "PayloadCertificateFileName": "ugam-ca.pem",
                "PayloadContent": ca_data,
            },
            {
                "PayloadType": "com.apple.vpn.managed",
                "PayloadIdentifier": "ugam.ikev2.vpn." + str(uuid.uuid4()).upper(),
                "PayloadUUID": str(uuid.uuid4()).upper(),
                "PayloadVersion": 1,
                "PayloadDisplayName": cfg["name"],
                "UserDefinedName": cfg["name"],
                "VPNType": "IKEv2",
                "IKEv2": {
                    "RemoteAddress": remote_addr,
                    "RemoteIdentifier": cfg["remote_id"],
                    "LocalIdentifier": cfg["username"],
                    "AuthenticationMethod": "Username",
                    "ExtendedAuthEnabled": 1,
                    "DisableMOBIKE": 0,
                    "DisableRedirect": 0,
                    "EnablePFS": 1,
                    "ChildSecurityAssociationParameters": {
                        "EncryptionAlgorithm": "AES-256",
                        "IntegrityAlgorithm": "SHA2-256",
                        "DiffieHellmanGroup": 14,
                        "LifeTimeInMinutes": 60,
                    },
                    "IKESecurityAssociationParameters": {
                        "EncryptionAlgorithm": "AES-256",
                        "IntegrityAlgorithm": "SHA2-256",
                        "DiffieHellmanGroup": 14,
                        "LifeTimeInMinutes": 240,
                    },
                },
                "OnDemandEnabled": 1,
                "OnDemandRules": [
                    {
                        "InterfaceTypeMatch": "Any",
                        "Action": "Connect",
                    }
                ],
            },
        ],
        "PayloadDisplayName": cfg["name"],
        "PayloadIdentifier": "ugam.ikev2." + prof_uuid,
        "PayloadUUID": prof_uuid,
        "PayloadType": "Configuration",
        "PayloadVersion": 1,
        # Учётные данные хранятся отдельно; пароль пользователь введёт при первом
        # подключении. Для автозаполнения можно добавить shared-secret payload.
    }
    # plist serialisation без внешних библиотек — пишем минимальный XML.
    return plist_xml(payload)


def plist_xml(obj, indent=0):
    pad = "\t" * indent
    lines = ['<?xml version="1.0" encoding="UTF-8"?>']
    lines.append('<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" '
                 '"http://www.apple.com/DTDs/PropertyList-1.0.dtd">')
    lines.append('<plist version="1.0">')
    lines.append(_plist_value(obj, 1))
    lines.append('</plist>')
    return "\n".join(lines) + "\n"


def _plist_value(v, indent):
    pad = "\t" * indent
    if isinstance(v, dict):
        out = [pad + "<dict>"]
        for k, val in v.items():
            out.append("\t" * (indent + 1) + f"<key>{_esc(k)}</key>")
            out.append(_plist_value(val, indent + 1))
        out.append(pad + "</dict>")
        return "\n".join(out)
    if isinstance(v, list):
        out = [pad + "<array>"]
        for item in v:
            out.append(_plist_value(item, indent + 1))
        out.append(pad + "</array>")
        return "\n".join(out)
    if isinstance(v, bool):
        return pad + ("<true/>" if v else "<false/>")
    if isinstance(v, int):
        return pad + f"<integer>{v}</integer>"
    if isinstance(v, (float,)):
        return pad + f"<real>{v}</real>"
    return pad + f"<string>{_esc(str(v))}</string>"


def _esc(s):
    return (
        s.replace("&", "&amp;")
        .replace("<", "&lt;")
        .replace(">", "&gt;")
        .replace('"', "&quot;")
        .replace("'", "&apos;")
    )


def main():
    raw = sys.stdin.read()
    if not raw.strip():
        print(json.dumps({"error": "empty input"}))
        sys.exit(1)
    try:
        inp = json.loads(raw)
    except ValueError as e:
        print(json.dumps({"error": f"bad json: {e}"}))
        sys.exit(1)

    cfg = {
        "username": inp.get("username", ""),
        "password": inp.get("password", ""),
        "name": inp.get("name", "ugam.pro IKEv2"),
        "host": inp.get("host", DEFAULT_HOST),
        "remote_id": inp.get("remote_id", DEFAULT_REMOTE_ID),
    }
    if not cfg["username"] or not cfg["password"]:
        print(json.dumps({"error": "username and password required"}))
        sys.exit(1)

    ca_pem = read_ca_pem()
    out = {
        "sswan": make_sswan(cfg),
        "mobileconfig": make_mobileconfig(cfg, ca_pem),
        "ca_pem": ca_pem,
        "ios_uuid": "ugam-ikev2-" + cfg["username"],
    }
    print(json.dumps(out))


if __name__ == "__main__":
    main()
