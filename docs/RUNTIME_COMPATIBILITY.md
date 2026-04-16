# Compatibilite Runtime PHP 8.3 Et MySQL 9.1

## Objectif

Ce document ne garde que les regles runtime qui nous sont utiles dans ce projet.

Il sert a eviter les correctifs opportunistes quand une page fonctionne sur un environnement
et casse sur un autre.

Sources officielles :

- PHP `PDOStatement::execute` :
  - https://www.php.net/manual/en/pdostatement.execute.php
- PHP `PDO::prepare` :
  - https://www.php.net/manual/en/pdo.prepare.php
- PHP `strftime` :
  - https://www.php.net/manual/en/function.strftime.php
- MySQL 9.1 date/time :
  - https://dev.mysql.com/doc/refman/9.1/en/date-and-time-functions.html
- MySQL 9.1 SQL mode :
  - https://dev.mysql.com/doc/refman/9.1/en/sql-mode.html

---

## 1. PDO `execute()`

### Regle Projet

- si une requete preparee n a **aucun placeholder**, appeler `execute()` sans argument
- ne pas appeler `execute([])` par habitude
- si une requete utilise `bindParam()` ou `bindValue()`, appeler ensuite `execute()` sans tableau
- si une requete a des placeholders et qu on ne fait pas de `bind*()`, passer uniquement le tableau des valeurs reelles

### Pourquoi

- la signature officielle est `execute(?array $params = null)`
- les params servent uniquement aux marqueurs de la requete preparee
- `PDO::prepare()` est pertinent surtout pour les requetes rejouees avec valeurs differentes

### Impact Projet

- sur nos pages SQL statiques, `prepare() + execute([])` n apporte rien
- pour une requete sans parametre, preferer :

```php
$stmt = $pdo->prepare($sql);
$stmt->execute();
```

ou, si la requete est purement statique :

```php
$stmt = $pdo->query($sql);
```

### Fichiers Sensibles

- [pages/reports.php](/var/www/html/pages/reports.php)

---

## 2. Dates MySQL recentes

### Regle Projet

- ne pas compter sur des conversions implicites de dates
- ne pas supposer qu une valeur date invalide sera toleree
- neutraliser explicitement :
  - `NULL`
  - chaine vide
  - `0000-00-00 00:00:00`
- ne pas multiplier plusieurs strategies de parsing dans une meme page

### Pourquoi

- MySQL 9.1 est plus strict sur les dates et leurs validations
- `STR_TO_DATE()` retourne `NULL` + warning si la valeur ne peut pas etre parsee
- en mode strict, les zero dates et zero parts sont problematiques
- la doc MySQL 9.1 indique aussi que `STR_TO_DATE()` fait un controle plus complet qu avant

### Impact Projet

- une page qui melange :
  - `CAST(... AS CHAR)`
  - `STR_TO_DATE(...)`
  - `DATE(...)`
  - `YEAR(...)`
  - `MONTH(...)`
  - tests ad hoc de zero date
  devient fragile entre environnements

### Regle de simplification

- une requete = une seule strategie date
- si la date est normalisee sous forme texte SQL standard `YYYY-MM-DD HH:MM:SS`,
  alors :
  - tri chronologique possible avec `ORDER BY created_at DESC`
  - jour = `LEFT(created_at, 10)`
  - mois = `LEFT(created_at, 7)`

Condition :

- cette strategie n est valide que si `created_at` est deja normalise a ce format

### Fichiers Sensibles

- [pages/reports.php](/var/www/html/pages/reports.php)

---

## 3. `strftime()` n est plus une base portable

### Regle Projet

- ne pas introduire de nouveau `strftime()` dans le projet
- remplacer progressivement l existant par :
  - `date()` si le format est fixe et independant de la locale
  - `IntlDateFormatter` si la locale humaine est necessaire

### Pourquoi

- `strftime()` est depreciee depuis PHP 8.1
- son comportement depend de la locale systeme et de la bibliotheque C
- la doc PHP signale explicitement des differences de support entre Windows et d autres plateformes

### Impact Projet

- deux serveurs peuvent afficher differemment le meme mois
- une page peut etre correcte sous Linux et incoherente sous Windows

### Fichiers Sensibles

- [pages/reports.php](/var/www/html/pages/reports.php)

---

## 4. Regle Projet De Compatibilite

Avant toute correction de page cassant entre environnements :

1. verifier si le probleme vient du runtime ou du code
2. consulter la doc officielle PHP/PDO/MySQL concernee
3. choisir une seule strategie technique par sujet
4. supprimer les alternatives concurrentes au lieu de les empiler

Ce qu on veut eviter :

- un parseur date par requete
- `prepare()` partout par reflexe
- `execute([])` meme sans placeholder
- des comportements qui ne tiennent que sur un moteur SQL ou un OS
