#!/usr/bin/env bash
#
# Nuvabill one-line installer
#
#   curl -fsSL https://nuvabill.com/install.sh | bash
#
# - On a server that already runs PHP 8.3 or newer (SSH, or Terminal in cPanel), it installs
#   Nuvabill into a folder and asks a few questions.
# - As root on a fresh Ubuntu 22.04/24.04 or Debian 12/13 server, it first sets up Nginx,
#   PHP 8.4, MariaDB, a free SSL certificate and the cron job.
#
# The download is checked against the Nuvabill release signature before anything is unpacked.
#
# Options: curl -fsSL https://nuvabill.com/install.sh | bash -s -- [options]
#   --dir DIR        Install into this folder (default: ./nuvabill, or /var/www/nuvabill on a new server)
#   --domain NAME    Your billing address on a new server, for example billing.yourhost.com
#   --server         Set up Nginx, PHP and MariaDB even if PHP is already installed (root only)
#   --version X.Y.Z  Install this version instead of the newest one
#   --zip FILE       Install from a release zip you downloaded; FILE.sig must be next to it
#   --yes            Say yes to every question (add the cron job, get an SSL certificate)
#   Other options go to "php artisan nuvabill:install", for example --db=sqlite or --email=you@example.com

REPO="meroxis/nuvabill"
PUBLIC_KEY="${NUVABILL_PUBLIC_KEY:-vH3YQXmgUOPpSb5UCWOWn0PT43HrQ76RQUFUnw8Emgw=}"
PHP_SERVER_VERSION="8.4"

DIR=""
DOMAIN=""
SERVER=0
VERSION=""
ZIP=""
YES=0
PASS=()
TTY=0
WORK=""

if [ -t 2 ]; then
    BOLD=$'\033[1m' GREEN=$'\033[32m' YELLOW=$'\033[33m' RED=$'\033[31m' RESET=$'\033[0m'
else
    BOLD="" GREEN="" YELLOW="" RED="" RESET=""
fi

info() { printf '%s==>%s %s\n' "$GREEN$BOLD" "$RESET" "$*" >&2; }
warn() { printf '%sNote:%s %s\n' "$YELLOW$BOLD" "$RESET" "$*" >&2; }
fail() { printf '%sError:%s %s\n' "$RED$BOLD" "$RESET" "$*" >&2; exit 1; }
have() { command -v "$1" >/dev/null 2>&1; }

usage() {
    sed -n '3,22p' "$0" 2>/dev/null | sed 's/^# \{0,1\}//' || true
    echo "See https://nuvabill.com/docs/"
}

# Ask a question on the terminal, even when this script is piped into bash.
ask() {
    local question=$1 default=${2:-} answer=""

    if [ "$TTY" = 1 ]; then
        printf '%s' "$question" >&2
        read -r answer <&3 || true
    fi

    printf '%s' "${answer:-$default}"
}

# confirm "Question?" y|n: yes with --yes, the default without a terminal.
confirm() {
    local answer=""

    [ "$YES" = 1 ] && return 0

    if [ "$TTY" = 1 ]; then
        printf '%s %s ' "$1" "$([ "$2" = y ] && echo '[Y/n]' || echo '[y/N]')" >&2
        read -r answer <&3 || true
    fi

    case "${answer:-$2}" in
        [Yy]*) return 0 ;;
        *) return 1 ;;
    esac
}

parse_options() {
    while [ $# -gt 0 ]; do
        case "$1" in
            --dir) DIR=${2:?--dir needs a folder}; shift 2 ;;
            --dir=*) DIR=${1#*=}; shift ;;
            --domain) DOMAIN=${2:?--domain needs a domain}; shift 2 ;;
            --domain=*) DOMAIN=${1#*=}; shift ;;
            --server) SERVER=1; shift ;;
            --version) VERSION=${2:?--version needs a number}; shift 2 ;;
            --version=*) VERSION=${1#*=}; shift ;;
            --zip) ZIP=${2:?--zip needs a file}; shift 2 ;;
            --zip=*) ZIP=${1#*=}; shift ;;
            --yes | -y) YES=1; shift ;;
            --help | -h) usage; exit 0 ;;
            *) PASS+=("$1"); shift ;;
        esac
    done

    VERSION=${VERSION#v}
}

check_php() {
    have php || fail "PHP is not installed. Install PHP 8.3 or newer first, or run this as root on a fresh Ubuntu or Debian server to set everything up."

    php -r 'exit(version_compare(PHP_VERSION, "8.3.0", ">=") ? 0 : 1);' </dev/null \
        || fail "Nuvabill needs PHP 8.3 or newer. The php command here is $(php -r 'echo PHP_VERSION;' </dev/null). In cPanel, choose a newer version under Select PHP Version."

    local missing
    missing=$(php -r '
        $missing = [];
        foreach (["zip", "sodium", "openssl", "curl", "mbstring", "intl", "bcmath", "xml", "pdo", "fileinfo", "tokenizer", "ctype"] as $extension) {
            if (! extension_loaded($extension)) { $missing[] = $extension; }
        }
        if (! extension_loaded("pdo_mysql") && ! extension_loaded("pdo_sqlite")) { $missing[] = "pdo_mysql"; }
        echo implode(" ", $missing);
    ' </dev/null)

    [ -z "$missing" ] || fail "Turn on these PHP extensions first: $missing"
}

# The folder must be new or empty (a cPanel cgi-bin or .well-known folder is fine).
prepare_dir() {
    if [ -e "$DIR" ]; then
        [ -d "$DIR" ] || fail "$DIR is a file, not a folder."

        local entry
        for entry in "$DIR"/* "$DIR"/.[!.]*; do
            [ -e "$entry" ] || continue
            case "$(basename "$entry")" in
                cgi-bin | .well-known) ;;
                *) fail "The folder $DIR is not empty. Choose a new folder with --dir." ;;
            esac
        done
    fi

    mkdir -p "$DIR" || fail "Could not create $DIR."
    DIR=$(cd "$DIR" && pwd)
}

# Download the release (or take --zip), check its signature and unpack it into $DIR.
fetch_release() {
    WORK=$(mktemp -d)
    trap 'rm -rf "$WORK"' EXIT

    if [ -n "$ZIP" ]; then
        [ -f "$ZIP" ] && [ -f "$ZIP.sig" ] || fail "Put the release zip and its .sig file side by side: $ZIP and $ZIP.sig"
        [ -n "$VERSION" ] || VERSION=$(basename "$ZIP" | sed -n 's/^nuvabill-\([0-9][0-9.]*\)\.zip$/\1/p')
        [ -n "$VERSION" ] || fail "Add --version, for example --version 0.4.1."
        cp "$ZIP" "$WORK/nuvabill.zip"
        cp "$ZIP.sig" "$WORK/nuvabill.zip.sig"
    else
        if [ -z "$VERSION" ]; then
            local latest
            latest=$(curl -fsSLI -o /dev/null -w '%{url_effective}' "https://github.com/$REPO/releases/latest") || fail "Could not reach GitHub. Check the internet connection of this server."
            VERSION=${latest##*/v}
        fi

        [[ "$VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || fail "Could not find the newest Nuvabill version. Try --version 0.4.1."
        info "Downloading Nuvabill $VERSION"
        curl -fL --progress-bar -o "$WORK/nuvabill.zip" "https://github.com/$REPO/releases/download/v$VERSION/nuvabill-$VERSION.zip" \
            || fail "The download failed."
        curl -fsSL -o "$WORK/nuvabill.zip.sig" "https://github.com/$REPO/releases/download/v$VERSION/nuvabill-$VERSION.zip.sig" \
            || fail "The signature file could not be downloaded."
    fi

    info "Checking the signature"
    php -r '
        $signature = base64_decode(trim((string) file_get_contents($argv[3])), true);
        $key = base64_decode($argv[4], true);
        if ($signature === false || $key === false || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES || strlen($key) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) { exit(1); }
        $message = "nuvabill-release:" . $argv[2] . ":" . hash_file("sha256", $argv[1]);
        exit(sodium_crypto_sign_verify_detached($signature, $message, $key) ? 0 : 1);
    ' "$WORK/nuvabill.zip" "$VERSION" "$WORK/nuvabill.zip.sig" "$PUBLIC_KEY" </dev/null \
        || fail "The download failed the signature check, so nothing was installed."

    info "Unpacking into $DIR"
    php -r '
        $zip = new ZipArchive;
        if ($zip->open($argv[1]) !== true || ! $zip->extractTo($argv[2])) { exit(1); }
        $zip->close();
    ' "$WORK/nuvabill.zip" "$DIR" </dev/null || fail "Could not unpack the release into $DIR."

    chmod -R u+rwX "$DIR/storage" "$DIR/bootstrap/cache"
}

# Run "php artisan nuvabill:install", as another user when one is given.
run_installer() {
    local user=$1
    shift
    local as=()
    [ -n "$user" ] && as=(runuser -u "$user" --)

    if ! (cd "$DIR" && "${as[@]+"${as[@]}"}" php artisan list --raw </dev/null 2>/dev/null | grep -q '^nuvabill:install'); then
        warn "Nuvabill $VERSION has no terminal installer. Open your site in a browser to finish with the web installer."
        return 1
    fi

    if [ "$TTY" = 1 ]; then
        (cd "$DIR" && "${as[@]+"${as[@]}"}" php artisan nuvabill:install "$@" <&3) || fail "The installer stopped. Fix the problem above, then run: cd $DIR && php artisan nuvabill:install"
    else
        (cd "$DIR" && "${as[@]+"${as[@]}"}" php artisan nuvabill:install --no-interaction "$@" </dev/null) || fail "The installer stopped. Fix the problem above, then run: cd $DIR && php artisan nuvabill:install"
    fi
}

cron_line() {
    printf '* * * * * cd %s && %s artisan schedule:run >> /dev/null 2>&1' "$DIR" "$(command -v php)"
}

# On a server that already has PHP: unpack, run the installer, offer the cron job.
install_app() {
    check_php
    DIR=${DIR:-$(pwd)/nuvabill}
    prepare_dir
    fetch_release

    local installed=0
    run_installer "" "${PASS[@]+"${PASS[@]}"}" && installed=1

    if [ "$installed" = 1 ] && have crontab && confirm "Add the cron job now, so renewals, reminders and updates run?" y; then
        local current
        current=$(crontab -l 2>/dev/null | grep -Fv "cd $DIR && " || true)
        if printf '%s\n%s\n' "$current" "$(cron_line)" | sed '/^$/d' | crontab -; then
            info "Cron job added for you."
        else
            warn "Could not add the cron job. Add the line above yourself (cPanel → Cron Jobs)."
        fi
    fi

    if [ "$(id -u)" = 0 ]; then
        warn "The files belong to root. Give them to your web server user, for example: chown -R www-data:www-data $DIR"
    fi

    echo >&2
    info "Nuvabill is in $DIR"
    echo "   Point your domain at $DIR/public (in cPanel: Domains → Manage → Document Root)." >&2
    echo "   If your host only serves the folder itself, Nuvabill's .htaccess sends visitors into public/." >&2
}

add_php_repository() {
    # Ubuntu and Debian 12 ship an older PHP; these are the usual trusted PHP repositories.
    if [ "$ID" = ubuntu ]; then
        apt-get install -y -qq software-properties-common >/dev/null
        add-apt-repository -y ppa:ondrej/php >/dev/null
    else
        curl -fsSL -o /tmp/debsuryorg-archive-keyring.deb https://packages.sury.org/debsuryorg-archive-keyring.deb
        dpkg -i /tmp/debsuryorg-archive-keyring.deb >/dev/null
        echo "deb [signed-by=/usr/share/keyrings/debsuryorg-archive-keyring.gpg] https://packages.sury.org/php/ $VERSION_CODENAME main" > /etc/apt/sources.list.d/php.list
    fi

    apt-get update -qq
}

write_nginx_site() {
    cat > /etc/nginx/sites-available/nuvabill <<NGINX
server {
    listen 80;
    listen [::]:80;
    server_name $DOMAIN;
    root $DIR/public;
    index index.php;
    charset utf-8;
    client_max_body_size 64m;

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    # Front-end files have a fingerprint in their name, so browsers may keep them for a year.
    location /build/assets/ {
        add_header Cache-Control "public, max-age=31536000, immutable";
        try_files \$uri =404;
    }

    location ~ \.php\$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php$PHP_SERVER_VERSION-fpm.sock;
        fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
        fastcgi_param DOCUMENT_ROOT \$realpath_root;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
NGINX

    ln -sf /etc/nginx/sites-available/nuvabill /etc/nginx/sites-enabled/nuvabill
    if [ -L /etc/nginx/sites-enabled/default ]; then
        rm -f /etc/nginx/sites-enabled/default
    fi

    nginx -t >/dev/null 2>&1 || fail "The Nginx settings are not valid. Check /etc/nginx/sites-available/nuvabill."
    systemctl reload nginx
}

# On a fresh Ubuntu or Debian server: Nginx, PHP, MariaDB, SSL, Nuvabill and the cron job.
install_server() {
    [ "$(id -u)" = 0 ] || fail "Setting up a server needs root. Run: curl -fsSL https://nuvabill.com/install.sh | sudo bash"
    [ -r /etc/os-release ] || fail "This does not look like Ubuntu or Debian."
    # shellcheck disable=SC1091
    . /etc/os-release

    case "${ID:-}:${VERSION_ID:-}" in
        ubuntu:22.04 | ubuntu:24.04 | debian:12 | debian:13) ;;
        *) fail "Server setup works on Ubuntu 22.04 or 24.04 and Debian 12 or 13. This is ${PRETTY_NAME:-another system}. Install PHP 8.3+ and a web server yourself, then run this again." ;;
    esac

    [ -n "$DOMAIN" ] || DOMAIN=$(ask "Address for Nuvabill, for example billing.yourhost.com: " "")
    DOMAIN=$(printf '%s' "$DOMAIN" | tr 'A-Z' 'a-z' | sed 's#^https\{0,1\}://##; s#/.*$##')
    [[ "$DOMAIN" =~ ^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$ ]] || fail "Give the address with --domain, for example --domain billing.yourhost.com"

    DIR=${DIR:-/var/www/nuvabill}
    prepare_dir

    info "Installing Nginx, MariaDB and PHP $PHP_SERVER_VERSION (a few minutes)"
    export DEBIAN_FRONTEND=noninteractive
    apt-get update -qq
    apt-get install -y -qq ca-certificates curl gnupg cron nginx mariadb-server certbot python3-certbot-nginx >/dev/null
    apt-cache show "php$PHP_SERVER_VERSION-fpm" >/dev/null 2>&1 || add_php_repository

    local packages=() extension
    for extension in fpm cli mysql sqlite3 mbstring xml curl zip intl bcmath gd; do
        packages+=("php$PHP_SERVER_VERSION-$extension")
    done
    apt-get install -y -qq "${packages[@]}" >/dev/null
    systemctl enable --now nginx mariadb cron "php$PHP_SERVER_VERSION-fpm" >/dev/null 2>&1 || true
    check_php

    info "Creating the database"
    local db_password
    db_password=$(php -r 'echo bin2hex(random_bytes(16));' </dev/null)
    mysql <<SQL
CREATE DATABASE IF NOT EXISTS nuvabill CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'nuvabill'@'localhost' IDENTIFIED BY '$db_password';
ALTER USER 'nuvabill'@'localhost' IDENTIFIED BY '$db_password';
GRANT ALL PRIVILEGES ON nuvabill.* TO 'nuvabill'@'localhost';
FLUSH PRIVILEGES;
SQL

    fetch_release
    chown -R www-data:www-data "$DIR"

    info "Setting up Nginx for $DOMAIN"
    write_nginx_site

    if have ufw && ufw status 2>/dev/null | grep -q '^Status: active'; then
        ufw allow 'Nginx Full' >/dev/null
    fi

    local scheme=http
    if confirm "Get a free SSL certificate from Let's Encrypt for $DOMAIN? The domain must already point to this server, and you accept the Let's Encrypt terms." y; then
        if certbot --nginx -d "$DOMAIN" --non-interactive --agree-tos --register-unsafely-without-email --redirect; then
            scheme=https
        else
            warn "The certificate did not work. Nuvabill runs on http for now. Later run: certbot --nginx -d $DOMAIN"
        fi
    fi

    run_installer www-data --url="$scheme://$DOMAIN" --db=mysql --db-host=localhost --db-port=3306 \
        --db-name=nuvabill --db-user=nuvabill --db-password="$db_password" "${PASS[@]+"${PASS[@]}"}" || true

    local current
    current=$(crontab -u www-data -l 2>/dev/null | grep -Fv "cd $DIR && " || true)
    printf '%s\n%s\n' "$current" "$(cron_line)" | sed '/^$/d' | crontab -u www-data -

    echo >&2
    info "Nuvabill is ready at $scheme://$DOMAIN"
    echo "   Admin area: $scheme://$DOMAIN/admin" >&2
    echo "   Files: $DIR · Database: nuvabill (password in $DIR/.env) · Cron job: added for www-data" >&2
}

main() {
    set -euo pipefail
    parse_options "$@"

    if { exec 3</dev/tty; } 2>/dev/null; then
        TTY=1
    fi

    have curl || fail "curl is not installed."

    echo "${BOLD}Nuvabill installer${RESET}" >&2

    if [ "$SERVER" = 1 ] || { [ "$(id -u)" = 0 ] && ! have php; }; then
        install_server
    else
        install_app
    fi
}

main "$@"
