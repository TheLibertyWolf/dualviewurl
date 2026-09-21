# Contribuer

Merci de contribuer à Duoviewurl. Les corrections de sécurité du proxy, l’accessibilité et la compatibilité sans compromettre la protection SSRF sont prioritaires.

## Procédure

1. Ouvrir une issue décrivant le besoin, sauf correction évidente et limitée.
2. Créer une branche depuis `main`.
3. Garder le projet sans framework lourd ni dépendance CDN obligatoire.
4. Ajouter ou adapter les tests, en particulier pour toute modification réseau.
5. Exécuter la construction, les tests et les contrôles de syntaxe.
6. Ouvrir une pull request claire en complétant la checklist.

```bash
docker build -t duoviewurl:test .
docker run --rm --entrypoint php duoviewurl:test /var/www/html/tests/run.php
docker run --rm duoviewurl:test sh -c "find /var/www/html/src /var/www/html/public -name '*.php' -print0 | xargs -0 -n1 php -l"
```

Ne placez jamais de clé privée, jeton, mot de passe, URL interne ou capture contenant des données sensibles dans une issue, un commit ou une pull request.
