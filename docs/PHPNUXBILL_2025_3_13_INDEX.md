# Index PHPNuxBill 2025.3.13

## Objet

Ce document enregistre une premiere cartographie locale de la source :

- [phpnuxbill-2025.3.13](/var/www/html/docs/phpnuxbill-2025.3.13)

Date d indexation :

- 30 mars 2026

## Resume Rapide

- emplacement : [phpnuxbill-2025.3.13](/var/www/html/docs/phpnuxbill-2025.3.13)
- volume observe : `1831` fichiers
- nature : application PHP complete avec base applicative propre, installateur, SQL, UI, systeme interne, plugins et integration RADIUS

## Fichiers Racine Importants

- [README.md](/var/www/html/docs/phpnuxbill-2025.3.13/README.md)
- [CHANGELOG.md](/var/www/html/docs/phpnuxbill-2025.3.13/CHANGELOG.md)
- [composer.json](/var/www/html/docs/phpnuxbill-2025.3.13/composer.json)
- [config.sample.php](/var/www/html/docs/phpnuxbill-2025.3.13/config.sample.php)
- [index.php](/var/www/html/docs/phpnuxbill-2025.3.13/index.php)
- [init.php](/var/www/html/docs/phpnuxbill-2025.3.13/init.php)
- [radius.php](/var/www/html/docs/phpnuxbill-2025.3.13/radius.php)
- [update.php](/var/www/html/docs/phpnuxbill-2025.3.13/update.php)
- [version.json](/var/www/html/docs/phpnuxbill-2025.3.13/version.json)

## Dossiers Racine Importants

- [/admin](/var/www/html/docs/phpnuxbill-2025.3.13/admin)
- [/docs](/var/www/html/docs/phpnuxbill-2025.3.13/docs)
- [/install](/var/www/html/docs/phpnuxbill-2025.3.13/install)
- [/pages_template](/var/www/html/docs/phpnuxbill-2025.3.13/pages_template)
- [/qrcode](/var/www/html/docs/phpnuxbill-2025.3.13/qrcode)
- [/scan](/var/www/html/docs/phpnuxbill-2025.3.13/scan)
- [/system](/var/www/html/docs/phpnuxbill-2025.3.13/system)
- [/ui](/var/www/html/docs/phpnuxbill-2025.3.13/ui)

## SQL Et Installation

Fichiers cle :

- [install/index.php](/var/www/html/docs/phpnuxbill-2025.3.13/install/index.php)
- [install/phpnuxbill.sql](/var/www/html/docs/phpnuxbill-2025.3.13/install/phpnuxbill.sql)
- [install/radius.sql](/var/www/html/docs/phpnuxbill-2025.3.13/install/radius.sql)
- [install/update.php](/var/www/html/docs/phpnuxbill-2025.3.13/install/update.php)

Interpretation initiale :

- `phpnuxbill.sql` porte vraisemblablement la base applicative propre
- `radius.sql` porte vraisemblablement la projection / integration RADIUS
- ce point est central pour analyser plus tard les mecanismes de recharge, cumul, expiration et comptabilisation

## Arborescence Technique Observee

### Zone application

- [/system/controllers](/var/www/html/docs/phpnuxbill-2025.3.13/system/controllers)
- [/system/devices](/var/www/html/docs/phpnuxbill-2025.3.13/system/devices)
- [/system/paymentgateway](/var/www/html/docs/phpnuxbill-2025.3.13/system/paymentgateway)
- [/system/plugin](/var/www/html/docs/phpnuxbill-2025.3.13/system/plugin)
- [/system/widgets](/var/www/html/docs/phpnuxbill-2025.3.13/system/widgets)

### Zone framework / bootstrap

- [system/boot.php](/var/www/html/docs/phpnuxbill-2025.3.13/system/boot.php)
- [system/api.php](/var/www/html/docs/phpnuxbill-2025.3.13/system/api.php)
- [system/orm.php](/var/www/html/docs/phpnuxbill-2025.3.13/system/orm.php)
- [/system/vendor](/var/www/html/docs/phpnuxbill-2025.3.13/system/vendor)
- [/system/autoload](/var/www/html/docs/phpnuxbill-2025.3.13/system/autoload)

### Zone interface

- [/ui/themes](/var/www/html/docs/phpnuxbill-2025.3.13/ui/themes)
- [/ui/ui](/var/www/html/docs/phpnuxbill-2025.3.13/ui/ui)
- [/ui/ui_custom](/var/www/html/docs/phpnuxbill-2025.3.13/ui/ui_custom)
- [/ui/conf](/var/www/html/docs/phpnuxbill-2025.3.13/ui/conf)

## Pistes D Analyse Prioritaires Plus Tard

Si on veut comprendre la logique de recharge / cumul / meme profil, les zones a lire en priorite seront probablement :

1. [install/phpnuxbill.sql](/var/www/html/docs/phpnuxbill-2025.3.13/install/phpnuxbill.sql)
2. [install/radius.sql](/var/www/html/docs/phpnuxbill-2025.3.13/install/radius.sql)
3. [/system/controllers](/var/www/html/docs/phpnuxbill-2025.3.13/system/controllers)
4. [/system/devices](/var/www/html/docs/phpnuxbill-2025.3.13/system/devices)
5. [radius.php](/var/www/html/docs/phpnuxbill-2025.3.13/radius.php)

Questions d indexation a garder en tete :

- ou est stocke le "reste" reel avant recharge
- ou est stocke le cumul autorise courant
- comment la base applicative parle a la base RADIUS
- si le meme profil est recharge, quelle valeur pivot est recalculee

## Etat

- indexation d arborescence : faite
- lecture metier detaillee : pas encore faite
- conclusion fonctionnelle : pas encore figee
