# Déploiement Docker

## Préparation

Le réseau de Nginx Proxy Manager doit exister :

```bash
docker network inspect npm_default
```

## Stack Portainer / Compose

```bash
git clone git@github-dualviewurl:TheLibertyWolf/dualviewurl.git
cd dualviewurl
docker compose up -d --build
docker compose ps
docker exec -u www-data -it dualviewurl-app php /var/www/html/bin/create-user.php admin 'un-mot-de-passe-long' admin
```

Le projet Compose porte explicitement le nom `dualurlview`. Le service interne est joignable par Nginx Proxy Manager à l’adresse `http://dualviewurl-app:80`.

SQLite fonctionne dans le processus PHP : il n’apparaît pas comme un conteneur séparé dans Portainer. La base se trouve dans le volume nommé `dualurlview_data`, visible dans **Volumes**. Ce volume conserve les comptes, les historiques, les sessions persistantes et les réglages Turnstile lors d’une recréation du conteneur.

La commande `create-user.php` crée le premier administrateur. Les comptes suivants et les clés Turnstile se gèrent ensuite avec le bouton **Admin**. Turnstile reste désactivé tant que sa clé de site et sa clé secrète ne sont pas toutes les deux enregistrées.

## Nginx Proxy Manager

Créer deux Proxy Hosts qui pointent vers le même conteneur :

- Domain Names : `dualviewurl.jessysystem.com`, puis `dualviewurl-view.jessysystem.com` ;
- Scheme : `http` ;
- Forward Hostname : `dualviewurl-app` ;
- Forward Port : `80` ;
- Block Common Exploits : actif ;
- Websockets Support : inutile ;
- certificat Let’s Encrypt avec Force SSL et HTTP/2.

Le premier domaine sert l’interface. Le second est une origine isolée réservée aux aperçus et doit également être autorisé dans les widgets CAPTCHA affichés.

## Mise à jour

```bash
git pull --ff-only
docker compose up -d --build
```

Ne lancez pas `docker compose down -v` lors d’une mise à jour : l’option `-v` supprimerait le volume SQLite et donc les données applicatives.
