#!/usr/bin/env python3
"""Генератор клиентских профилей L2TP/IPsec (нативный «L2TP» в iOS/macOS, «L2TP/IPsec
с общим ключом» в Windows, strongSwan-клиент с type=l2tp-psk в Android).

Это НЕ IKEv2 (нет CA-сертификата и сервисного клиента) — это IKEv1 + XAuth + PSK,
поверх которого клиент сам терминирует L2TP/PPP (серверный xl2tpd не требуется для
клиентского L2TP-over-IPsec; туннель PPP/IP терминируется на стороне клиента).

Вход — JSON на stdin:
{
  "username": "u_<id>",
  "password": "<пароль>",
  "psk":      "<общий ключ IPsec>",
  "name":     "имя профиля",
  "host":     "2.26.124.62"
}

Выход — JSON на stdout:
{
  "sswan": "<текст .sswan>",
  "mobileconfig": "<текст .mobileconfig>",
  "error": null
}
"""

import json
import sys
import uuid

DEFAULT_HOST = "2.26.124.62"

# IKEv1/ESP предложения, согласованные с серверным ikev1.conf.
# aes-sha1-modp1024 — legacy для нативного L2TP iOS/Windows (те требуют классику).
IKE = "aes256-sha1-modp1024,aes128-sha1-modp1024"
ESP = "aes256-sha1-modp1024,aes128-sha1-modp1024"


def make_sswan(cfg):
    """Android strongSwan профиль (.sswan) с type=l2tp-psk."""
    return json.dumps(
        {
            "uuid": str(uuid.uuid4()),
            "name": cfg["name"],
            "type": "l2tp-psk",
            "remote": {
                "addr": cfg["host"],
                # В IKEv1-XAuth remote id — это группа PSK, а не серверный SAN.
                "id": cfg["host"],
            },
            "local": {
                "auth": "xauth",
                "xauth_id": cfg["username"],
            },
            "ike": IKE,
            "esp": ESP,
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


def make_mobileconfig(cfg):
    """iOS/macOS профиль (.mobileconfig) — VPNType L2TP + shared secret."""
    prof_uuid = str(uuid.uuid4()).upper()
    payload = {
        "PayloadContent": [
            {
                "PayloadType": "com.apple.vpn.managed",
                "PayloadIdentifier": "ugam.l2tp.vpn." + str(uuid.uuid4()).upper(),
                "PayloadUUID": str(uuid.uuid4()).upper(),
                "PayloadVersion": 1,
                "PayloadDisplayName": cfg["name"],
                "UserDefinedName": cfg["name"],
                "VPNType": "L2TP",
                "VPN": {
                    "RemoteAddress": cfg["host"],
                    "AuthName": cfg["username"],
                    "AuthPassword": cfg["password"],
                    "SharedSecret": cfg["psk"],
                    "SendAllTraffic": 1,
                },
                "IPSec": {
                    "RemoteAddress": cfg["host"],
                    "AuthenticationMethod": "SharedSecret",
                    "SharedSecret": cfg["psk"],
                    "LocalIdentifier": cfg["username"],
                    "LocalIdentifierType": "KeyID",
                    "PromptForVPNPIN": 0,
                },
                "Proxies": {},
                "OnDemandEnabled": 0,
            },
        ],
        "PayloadDisplayName": cfg["name"],
        "PayloadIdentifier": "ugam.l2tp." + prof_uuid,
        "PayloadUUID": prof_uuid,
        "PayloadType": "Configuration",
        "PayloadVersion": 1,
    }
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
        "psk": inp.get("psk", ""),
        "name": inp.get("name", "ugam.pro L2TP"),
        "host": inp.get("host", DEFAULT_HOST),
    }
    if not cfg["username"] or not cfg["password"] or not cfg["psk"]:
        print(json.dumps({"error": "username, password and psk required"}))
        sys.exit(1)

    out = {
        "sswan": make_sswan(cfg),
        "mobileconfig": make_mobileconfig(cfg),
    }
    print(json.dumps(out))


if __name__ == "__main__":
    main()
