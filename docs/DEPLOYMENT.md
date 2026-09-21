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
```

Le projet Compose porte explicitement le nom `dualurlview`. Le service interne est joignable par Nginx Proxy Manager à l’adresse `http://dualviewurl-app:80`.

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
