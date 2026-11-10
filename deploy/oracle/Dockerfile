# Pin the official Mautic 7.2.2 Apache multi-platform manifest by digest.
# This manifest supports linux/amd64 and linux/arm64 (Oracle Ampere).
# Update only after reviewing the upstream release, digest and image architecture.
FROM mautic/mautic:7.2.2-20261026-apache@sha256:83461b9ec7c109413281490180e1f96c379aa935bd92f80c68294f98487a9015

# Mautic's official custom-plugin image layout.
COPY --chown=www-data:www-data plugins/MauticBillionMailBundle /var/www/html/docroot/plugins/MauticBillionMailBundle

# WallzaOS SSO plugin. It has no tenant credentials baked into the image;
# per-tenant endpoint, tenant ID and HMAC secret are provided at runtime.
COPY --chown=www-data:www-data plugins/MauticWallzaIdentityBundle /var/www/html/docroot/plugins/MauticWallzaIdentityBundle
