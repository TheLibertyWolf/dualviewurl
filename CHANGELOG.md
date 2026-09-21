# Journal des modifications

Les changements notables de Duoviewurl sont documentés ici selon [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/). Le projet suit le [versionnage sémantique](https://semver.org/lang/fr/).

## [1.2.2] - 2026-09-21

### Corrigé

- origine HTTPS isolée pour les vues proxifiées afin de rendre les CAPTCHA compatibles avec leur contrôle de hostname ;
- maintien de l’isolation entre les scripts distants et l’interface principale ;
- partage et effacement sécurisés de la session distante depuis l’origine principale.

## [1.2.1] - 2026-09-21

### Ajouté

- exécution directe des scripts officiels Turnstile, reCAPTCHA et hCaptcha avec CSP restreinte à leurs domaines ;
- conservation des widgets CAPTCHA dans les pages proxifiées lorsque le hostname Duoviewurl est autorisé par leur propriétaire.

## [1.2.0] - 2026-09-21

### Ajouté

- session distante temporaire et isolée par navigateur, partagée entre les deux vues ;
- transmission des formulaires POST classiques et suivi des redirections de connexion ;
- rechargement automatique de l’autre vue après une connexion réussie ;
- bouton « Effacer la session » supprimant immédiatement les cookies distants ;
- exclusion des requêtes proxy du journal d’accès Apache.

## [1.1.2] - 2026-09-21

### Ajouté

- détection des widgets Turnstile, reCAPTCHA et hCaptcha avec une explication visible et un lien vers la page originale.

## [1.1.1] - 2026-09-21

### Corrigé

- libération fiable du séparateur après un glissement au-dessus des iframes, une annulation du pointeur ou une perte de focus.

## [1.1.0] - 2026-09-21

### Ajouté

- sélecteur d’appareil indépendant dans chaque vue avec presets desktop, laptop, iPad, iPhone, Pixel et Galaxy ;
- largeur réelle de viewport et User-Agent associés à chaque preset ;
- comparaisons libres desktop/mobile, mobile/mobile ou desktop/desktop avec thèmes distincts.

### Corrigé

- limite par IP portée à 1 200 requêtes proxifiées par minute pour tenir compte des ressources chargées par les deux vues.
- chargement des CSS et polices dans les iframes sandboxées grâce aux en-têtes CORS du proxy ;
- suppression des attributs SRI devenus invalides après réécriture des ressources.
- lecture des vidéos et fichiers audio par segments HTTP de 4 Mio avec prise en charge de `Range` et `Content-Range`.

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

[1.2.2]: https://github.com/TheLibertyWolf/dualviewurl/releases/tag/v1.2.2
[1.2.1]: https://github.com/TheLibertyWolf/dualviewurl/releases/tag/v1.2.1
[1.2.0]: https://github.com/TheLibertyWolf/dualviewurl/releases/tag/v1.2.0
[1.1.2]: https://github.com/TheLibertyWolf/dualviewurl/releases/tag/v1.1.2
[1.1.1]: https://github.com/TheLibertyWolf/dualviewurl/releases/tag/v1.1.1
[1.1.0]: https://github.com/TheLibertyWolf/dualviewurl/releases/tag/v1.1.0
[1.0.1]: https://github.com/TheLibertyWolf/dualviewurl/releases/tag/v1.0.1
[1.0.0]: https://github.com/TheLibertyWolf/dualviewurl/releases/tag/v1.0.0
