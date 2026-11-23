The document root is `public/`. Only that directory is served; everything else in the
repository (config, includes, language, cache, ...) is not reachable from the web.

All requests that don't match a file in `public/` go to the front controller
`public/index.php` (Symfony). That includes the game's own URLs like `/game/overview`,
`/admin/bans` or `/account/board`, which are routes in Symfony, not files.

Example nginx server block (the `map` belongs in the `http` context, e.g. a file in `conf.d/`):

```
# serve foo.png.webp instead of foo.png to browsers that accept WebP (copies made by bin/console app:images:webp;
# the theme images already ship as WebP, so this only matters for the remaining JPG/PNG/GIF files)
map $http_accept $webp_suffix {
    default        "";
    "~*image/webp" ".webp";
}

# CSS/JS URLs carry ?v=<git hash or mtime>: cache them for good; unversioned ones revalidate
map $arg_v $asset_cache_control {
    default "public, max-age=31536000, immutable";
    ""      "no-cache";
}

# versioned images and the icon sprite (?v=<mtime>): immutable too; unversioned images keep the one-year cache with revalidation
map $arg_v $image_cache_control {
    default "public, max-age=31536000, immutable";
    ""      "public, max-age=31536000, stale-while-revalidate=604800";
}

# compression: see "Compression" below (precompressed .br/.gz files, brotli with the ngx_brotli module)
gzip on;
gzip_comp_level 5;
gzip_vary on;
gzip_static on;
gzip_types text/css application/javascript text/javascript application/json image/svg+xml;

brotli on;                  # ngx_brotli (optional module); delete the brotli lines without it
brotli_comp_level 5;        # dynamic HTML: 4-5 is fast enough; static files are precompressed at level 11
brotli_static on;           # serve foo.css.br when it exists and the client accepts br
brotli_types text/css application/javascript text/javascript application/json image/svg+xml;

server {
    server_name v0game.example;
    root /var/www/v0game/public;

    # Universe prefixes for static files: /uni2/styles/... -> /styles/...
    location ~ ^/uni[0-9]+/(styles|scripts)/ {
        rewrite ^/uni[0-9]+/(.*)$ /$1 last;
    }

    # versioned CSS/JS: immutable (see public/.htaccess)
    location ~* \.(css|js|mjs)$ {
        add_header Cache-Control $asset_cache_control;
        try_files $uri =404;
    }

    # WebP copies of static images (same as the Apache rule in public/.htaccess)
    location ~* \.(png|jpe?g|gif)$ {
        add_header Vary Accept;
        add_header Cache-Control $image_cache_control;
        try_files $uri$webp_suffix $uri =404;
    }

    location ~* \.(svg|woff2?|ico)$ {
        add_header Cache-Control $image_cache_control;
        try_files $uri =404;
    }

    # Theme metadata files are read by PHP, not served
    location ~ \.cfg$ {
        deny all;
    }

    location / {
        try_files $uri /index.php$is_args$args;
    }

    location ~ ^/index\.php(/|$) {
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
        fastcgi_split_path_info ^(.+\.php)(/.*)$;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        fastcgi_param DOCUMENT_ROOT $realpath_root;
    }

    # a request for any .php file goes to the front controller (the game's URLs are Symfony routes, not files)
    location ~ \.php$ {
        rewrite ^ /index.php last;
    }
}
```



## Compression

- **Brotli** (`ngx_brotli`, e.g. `apt install libnginx-mod-http-brotli-filter libnginx-mod-http-brotli-static`) is about
  15 % smaller than gzip: CSS of a game page 79 KB gzip, 68 KB brotli; HTML 9-17 KB, 8-14 KB. The config above enables it
  for dynamic responses (level 5) and for precompressed files.
- **Precompressed files:** `gzip_static on;` and `brotli_static on;` serve `foo.css.br` / `foo.css.gz` when it exists
  and the client accepts it, so CSS, JS and the icon sprite are compressed once at the highest level instead of on every
  request. `tools/build/precompress.sh` writes them next to the sources (css, js, svg, json below `public/styles` and
  `public/scripts`; skips `.br` when `brotli` is not installed). Run it after every deploy; stale files are rebuilt
  because the source is newer. The files are generated and not committed.
- **Apache:** `public/.htaccess` uses `mod_brotli` when loaded, else `mod_deflate`. `mod_deflate` changes the ETag to
  `"...-gzip"`, and Apache then answers a conditional request carrying that ETag with a full `200` instead of `304`.
  Best fix, in the vhost/server config (not allowed in `.htaccess`): `DeflateAlterETag NoChange` (Apache 2.4.58+ also
  for mod_brotli: `BrotliAlterETag NoChange`). Without access to it, `public/.htaccess` drops the ETag of CSS, JS, SVG and
  JSON (`Header unset ETag`), so the browser revalidates with `If-Modified-Since` and gets `304`. `?v=` assets are
  immutable and never revalidated either way.
- **Caching:** `?v=` URLs (CSS, JS, the icon sprite, image variants) get `public, max-age=31536000, immutable`; unversioned
  images keep the one-year cache with `stale-while-revalidate`; HTML stays `max-age=0, must-revalidate` (not `no-store`,
  so the back/forward cache keeps working).

## Public pages (HTTP cache)

Anonymous GETs of the public pages (news, rules, universe rules, hall of fame, battle hall, team, legal pages) are sent
with `Cache-Control: public, max-age=300, s-maxage=300`, public combat reports with one day and an ETag; none of them
sets a cookie. Logged-in visitors, `?lang=` and the login/register/lost-password forms stay private. A reverse proxy
cache (`proxy_cache`/`fastcgi_cache`) may store them, but must honour `Vary: Accept-Language, Cookie` (e.g. bypass the
cache when a `2Moons` or `REMEMBERME` cookie is present). Details: `docs/PERFORMANCE.md`, "Performance pass 3".
