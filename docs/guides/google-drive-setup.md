# Guide: Connect Google Drive with your own credentials (outline)

1. Go to Google Cloud Console → create a new project (e.g. "My Site Backups").
2. APIs & Services → Library → enable **Google Drive API**.
3. OAuth consent screen → User type **External** → fill app name, support email.
4. Scopes → add only `.../auth/drive.file`.
5. Credentials → Create credentials → **OAuth client ID** → type **Web application**.
6. Paste the **Authorized redirect URI** shown in the plugin (copy button).
7. Copy Client ID + Client Secret into the plugin.
8. **Publish the app** (Publishing status → In production). Otherwise access expires after 7 days.
9. Click **Connect** in the plugin and approve.
10. Run "Test connection".

Screenshots: `assets/images/guides/gdrive-01.png` … `gdrive-10.png`
Troubleshooting: redirect_uri_mismatch, "app not verified" warning (safe to continue for your own app), invalid_grant after 7 days.
