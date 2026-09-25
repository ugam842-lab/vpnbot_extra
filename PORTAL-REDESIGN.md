# Portal redesign — decouple TG grant from a single subscription

Status: foundation (bindings v2) + admin dashboard landed and deployed. Protocol
card (user-facing) is next; VLESS + hysteria in subscription after that.

## Current model (what exists today)

- `pac.user_portal_bindings` = `{ telegramId: subscriptionId }` (1:1).
- "Subscription" = an xray parent client (VLESS), id via
  `getClientSubscriptionId()` = `subscription_id ?? id`.
- WG (AmneziaWG) devices and xray runtime devices hang off the same
  `subscription_id` (HWID storage keyed by ownerSubId).
- Grant UI lives inside the per-client menu (`userXr $i` → `/userPortalGrant $i`).

## Target model (confirmed by owner)

1. TG ID is granted in a top-level **Settings → Portal users** menu, not inside a
   client.
2. A TG user owns a **set of protocol grants** (VLESS, WG, hysteria, later
   Shadowsocks + IKEv2) rather than one subscription.
3. The user sees a **protocol card**: which protocols they *can* use vs which
   they *are* using (derived from admin state, minimal data).
4. The admin sees a **dashboard**: whose TG ID is bound, who activated (pressed
   the button), grant/revoke.

## Data model

`pac.user_portal_bindings` value upgrades from a bare string to a record,
backward-compatible:

```json
{
  "123456789": {
    "subscription_id": "sub_abc",
    "granted_at": 1720000000,
    "activated": false
  }
}
```

Legacy string values are read through `normalizeUserPortalBinding()` and still
resolve — no migration pass needed.

## Phases

1. **Foundation** [done]: `normalizeUserPortalBinding()`, backward-compatible
   `getUserPortalBinding()`/`setUserPortalBinding()`, plus `getUserPortalRecords()`
   and `markUserPortalActivated()`. Unit-tested.
2. **Admin settings menu** [done]: `Settings → Portal users` lists records (bound TG
   id, subscription, activated), grant/revoke by TG id — replaces the per-client
   button. Implemented as `userPortalUsers()` dashboard + two-step
   `userPortalGrantPrompt`/`userPortalGrantPromptSub`/`userPortalGrantSave` and
   `userPortalRevokePrompt`/`userPortalRevokeSave` reply flows. Feedback via
   `userPortalSetFlash` (read by `userPortalUsers()`). Non-admins gated by
   `$this->admin`. 18 unit tests green.
3. **Protocol card**: user-facing view listing VLESS / WG / hysteria availability
   and usage (traffic from `getSubscriptionXrayTrafficTotals` /
   `getSubscriptionAwgTrafficTotals`).
4. **VLESS + hysteria in portal subscription**: ensure the user's subscription
   configs include VLESS and hysteria, not just WG.

## Protocol availability notes (for phase 3)

- VLESS = the xray parent client; availability already resolves via
  `resolveSubscriptionClient($subId)` (non-null = granted, `off`/missing = null).
- WG (AmneziaWG) = HWID devices keyed by `subscription_id`
  (`getHwidDevicesByUser($ownerSubId)`); "used" = devices/traffic > 0.
- hysteria = separate `hy` container; not yet tied to `subscription_id` — its
  availability/usage mapping must be established before it appears on the card.
- Traffic: xray via `getSubscriptionXrayTrafficTotals`, AWG via
  `getSubscriptionAwgTrafficTotals`.
