# Theme Minimal Change Request Template

Use this template when Moodle admin settings are insufficient and host-side theme code changes are required.

## Request Title

Login page layout cleanup and local login centering

## Business Background

- Current login page shows duplicated/undesired right-side login descriptions/buttons.
- SAML2 has been disabled by policy.
- Local login panel should remain as the single entry point.
- Admin needs ongoing ability to modify login button text from UI.

## Required Outcomes

1. Remove right-side SAML2-related region from login page rendering.
2. Remove top descriptive texts (browser/cookie reminder + external website login phrase).
3. Center local LDAP/native login form under logo.
4. Keep login button text sourced from language string (not hardcoded), so admins can manage it in Language customization.

## Suspected File Targets (Host Theme Repo)

- `theme/<active_theme>/templates/core/loginform.mustache`
- `theme/<active_theme>/templates/login.mustache` (if exists)
- `theme/<active_theme>/scss/*.scss` (or equivalent style entry)
- Optional renderer override if used by theme/plugin.

## Implementation Notes

- Remove duplicated identity provider rendering blocks if present.
- Keep standard Moodle local auth form behavior unchanged.
- Use responsive-safe CSS centering (desktop and mobile).
- Do not hardcode `TM employee Login`; use translatable string key.

## Acceptance Criteria

- Login page shows only local login form (LDAP/native).
- No SAML2 section appears.
- No `[您的瀏覽器]` and `[Login from TM ...]` text appears.
- Login form is visually centered below logo.
- Login button label can be changed via:
  - `Site administration > Language > Language customization`
- Login success/failure behavior remains identical to baseline.

## Test Plan (Host Team)

1. Purge caches after deployment.
2. Test in private mode:
   - Wrong password -> expected error.
   - Correct LDAP/native account -> successful login.
3. Cross-browser check:
   - Chrome
   - Edge
4. Attach before/after screenshots.

## Rollback

- Restore previous theme template and SCSS revision.
- Purge caches.
- Re-verify login path.

## Information Needed from Host Team

- Active theme name and version.
- Exact changed files and commit hash.
- Deployment timestamp and rollback contact.
