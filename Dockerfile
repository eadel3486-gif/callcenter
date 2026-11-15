FROM php:8.2-cli
WORKDIR /app
COPY . /app
# عدة عمليات متوازية (تليجرام + المتصفحات + مواقع المناديب في نفس الوقت)
ENV PHP_CLI_SERVER_WORKERS=6
ENV PORT=8080
ENV DB_PATH=/data/callcenter.sqlite
RUN mkdir -p /data /app/data
CMD ["sh", "-c", "php -S 0.0.0.0:${PORT} -t /app /app/router.php"]
