# ─────────────────────────────────────────────────────────────
#  BAF Weather Mirror — Koyeb / Render / যেকোনো Docker হোস্টের জন্য
# ─────────────────────────────────────────────────────────────
#  InfinityFree-এর তুলনায় এখানে যা পাওয়া যায়:
#    • সব পোর্ট খোলা      → ৮৪টি প্রক্সিই ব্যবহারযোগ্য (৪১ নয়)
#    • সময়ের সীমা নেই     → একটা কাজ করা প্রক্সি না পাওয়া পর্যন্ত চেষ্টা
#    • বট-ব্লক নেই        → পটভূমির কর্মী নিজে থেকেই আপডেট করে
#    • সার্ভার সবসময় চালু → cron লাগে না
# ─────────────────────────────────────────────────────────────
FROM php:8.3-cli-alpine

# GD (থাম্বনেইল) — curl, json, mbstring, openssl আগে থেকেই আছে
RUN apk add --no-cache --virtual .build libjpeg-turbo-dev libpng-dev freetype-dev \
 && apk add --no-cache libjpeg-turbo libpng freetype tini \
 && docker-php-ext-configure gd --with-jpeg --with-freetype \
 && docker-php-ext-install -j"$(nproc)" gd \
 && apk del .build

WORKDIR /app
COPY server/ /app/
COPY deploy/worker.php /app/worker.php
COPY deploy/start.sh  /start.sh
RUN chmod +x /start.sh && mkdir -p /app/data && chmod 777 /app/data

# হোস্ট নিজে PORT দেয় (Koyeb 8000, Render 10000) — না দিলে 8080
ENV PORT=8080 \
    PHP_CLI_SERVER_WORKERS=4 \
    REFRESH_SECONDS=300

EXPOSE 8080

# tini = সঠিকভাবে সিগন্যাল সামলানো, নাহলে কনটেইনার ঠিকমতো থামে না
ENTRYPOINT ["/sbin/tini", "--"]
CMD ["/start.sh"]
