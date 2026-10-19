# 最小底包：alpine（基础镜像约 7MB，自带 busybox 的 tar/gzip，可直接解压管道）
FROM alpine:3.20

# 仅安装下载器与证书：
#   curl            —— 只在构建期用来拉取静态 PHP（下面一步用完即删）
#   ca-certificates —— 保留，api=send 走 HTTPS 出站时校验证书
RUN apk add --no-cache ca-certificates \
 && apk add --no-cache --virtual .fetch curl

# 静态编译的 PHP 8.4.1 CLI（官方预编译产物，无编译/无 Composer/无包管理，不需本地构建）
# tar 包内仅含 `php`，解压后即 /usr/local/bin/php
RUN curl -fsSL https://dl.static-php.dev/static-php-cli/common/php-8.4.1-cli-linux-x86_64.tar.gz | tar -xz -C /usr/local/bin \
 && apk del .fetch \
 && php -v

# 非 root 运行
RUN adduser -D -u 10001 app

WORKDIR /app
COPY --chown=app:app . /app

# .http 挂载点：配置 config/httpviewer.config.json 的 dir 指向 "http"（相对项目根 -> /app/http）
# 运行时把宿主 .http 目录挂进来：
#   docker run --rm -p 127.0.0.1:8099:8099 -v "$(pwd)/http:/app/http:ro" rest-client-web
# 说明：挂载点必须在项目根 (/app) 之内，否则 Config::resolveDir() 会报「配置目录越界」
VOLUME ["/app/http"]

USER app

ENV PHP_CLI_SERVER_WORKERS=20

EXPOSE 8099

# README 要求监听地址：容器内必须 0.0.0.0 才能做端口映射（宿主机侧请只映射到 127.0.0.1）
CMD ["php", "-S", "0.0.0.0:8099"]
