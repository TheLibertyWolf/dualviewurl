# Journal des modifications

Les changements notables de Duoviewurl sont documentés ici selon [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/). Le projet suit le [versionnage sémantique](https://semver.org/lang/fr/).

## [1.0.1] - 2026-09-21

### Corrigé

- respect explicite de l’attribut `hidden` pour ne pas afficher l’état de chargement avant la saisie d’une URL.

## [1.0.0] - 2026-09-21

### Ajouté

- interface à deux panneaux responsive et accessible ;
- redimensionnement souris, tactile et clavier ;
- choix du User-Agent et du thème par panneau ;
- synchronisation optionnelle des liens ;
- mémorisation locale et partage par URL ;
- proxy PHP avec réécriture HTML/CSS ;
- protections SSRF IPv4/IPv6, épinglage DNS, limites de taille, durée, redirections et débit ;
- déploiement Docker isolé derrière Nginx Proxy Manager ;
- tests automatisés et workflow CI.

[1.0.1]: https://github.com/TheLibertyWolf/dualviewurl/releases/tag/v1.0.1
[1.0.0]: https://github.com/TheLibertyWolf/dualviewurl/releases/tag/v1.0.0
