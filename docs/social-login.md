# Social login (OAuth2)

Players can sign in or register with an existing Discord, Facebook, GitHub or Google account. All providers are off by
default. Settings: admin menu "Social login" (`/admin/config/social`, category `social` of the basic settings).

## Settings

Per provider: `social_<p>_enabled`, `social_<p>_client_id`, `social_<p>_client_secret` (the secret is never sent back to the
form; the field shows a hint, an empty input keeps the stored value). A provider counts only when it is on and has both
credentials. The callback URL is built from `DEFAULT_URI` (`.env.local`); without it (outside development) no provider works.

## Callback URLs and scopes

The callback is always at the site root: `https://<host>/login/<provider>/callback` (shown read-only in the admin page).

| provider | where to create the app | scopes |
|---|---|---|
| discord | https://discord.com/developers/applications (OAuth2, add the redirect) | `identify email` |
| facebook | https://developers.facebook.com/apps/ (use case Facebook Login, valid OAuth redirect URI) | `email public_profile` |
| github | https://github.com/settings/developers (OAuth App, authorization callback URL) | `read:user user:email` |
| google | https://console.cloud.google.com/apis/credentials (OAuth client ID, type Web application) | `openid email profile` |

## Flow

`GET /login/<p>` stores `state` (32 random bytes), a PKCE verifier (S256) and the intent (login | link) in the session and
redirects to the provider. `GET /login/<p>/callback` checks the state (constant time, single use, 10 minutes, same
provider), exchanges the code server to server (10 s timeout) and loads the user. Then:

1. identity already linked: that account is logged in (same path as the e-mail verification link: `GameAuth::login()`);
2. intent `link` with a logged-in account (button in the account settings): the identity is connected to it, unless another
   account owns it;
3. not linked and an account has the same **verified** e-mail address: never linked automatically. The identity waits in the
   session (15 minutes) on `/login/social/link`, where the owner enters that account's password once;
4. not linked and no such account: `/login/social/complete` (account name, rules, privacy) creates the account with an
   unusable password (set one later with "lost password"), links and logs in. The player picks a universe in the account
   lobby, as after the normal registration.

Verified e-mail: Discord `verified`, Google `email_verified`, GitHub the primary verified entry of `/user/emails`. Facebook's
address is never treated as verified, so Facebook cannot register or match by e-mail; it can log in once linked from the
account settings. Disconnecting the last login method of an account without a usable password is refused.

## Data

Table `accounts_social` (account_id, provider, provider_uid, email, created_at, last_login_at; unique (provider,
provider_uid)). Links are removed when an account is deleted. Tokens are never stored or logged.

## Code

`src/Security/Social/` (provider catalog, OAuth client, session state, resolver, rate limits), controllers
`Login/SocialLoginController`, `Account/SocialConnectionsController`, `Admin/ConfigSocialController`. Tests:
`tests/Unit/Security/Social`, `tests/Integration/SocialLoginResolutionTest.php`.
