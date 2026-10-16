# 07 – Google Drive OAuth (own credentials)

## User flow
1. Destinations → Add → Google Drive.
2. Plugin shows the **exact Redirect URI** (copy button), e.g. `https://site.com/wp-admin/admin.php?page=ovb-destinations&ovb_oauth=gdrive`.
3. User follows `docs/guides/google-drive-setup.md`, pastes Client ID + Secret.
4. "Connect" → Google consent → callback → plugin stores encrypted refresh token.
5. Plugin creates/finds the backup folder and runs a test upload/delete.

## Technical rules
- Scope: **`https://www.googleapis.com/auth/drive.file`** only (non-sensitive, no Google verification needed).
- `access_type=offline`, `prompt=consent` to guarantee a refresh token.
- `state` parameter = nonce + destination id, validated on callback.
- Refresh access token when < 5 min left; detect `invalid_grant` → mark destination "Needs reconnect" + admin notice.
- Uploads: resumable sessions, chunk size multiple of 256 KB (default 8 MB), session URL saved for resume.

## Critical pitfall to document
Apps left in **"Testing"** publishing status get refresh tokens that expire after **7 days**. The guide and the UI must tell users to **Publish the app (In production)**. Add a health check: if token fails ~7 days after connect, show this hint.
