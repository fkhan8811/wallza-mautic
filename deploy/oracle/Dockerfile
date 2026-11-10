# Pin the official, dated Mautic 7.2.2 Apache image.
# Update this tag only after reviewing the upstream Mautic release and image digest.
FROM mautic/mautic:7.2.2-20261026-apache

# Mautic's official custom-plugin image layout.
COPY --chown=www-data:www-data plugins/MauticBillionMailBundle /var/www/html/docroot/plugins/MauticBillionMailBundle
