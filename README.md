# Book Manager

Application d'écriture : édite tes livres en Markdown dans une interface façon
Notion (thème sombre), et fais relire/corriger/reformuler tes chapitres par
des agents Claude Code, avec toujours un aperçu (diff) à valider avant que
quoi que ce soit ne touche tes fichiers réels.

Stack volontairement minimale pour rester simple à héberger : **PHP + SQLite +
fichiers Markdown**, pas de framework, pas de dépendance à installer via
Composer/npm. Les agents tournent via le **CLI Claude Code**, authentifié
avec ton abonnement Claude.ai (pas de clé API facturée à l'usage).

## Comment ça marche

- Chaque livre = un dossier sur disque (`data/books/<slug>/`) avec ses
  chapitres en `.md`, plus un `.claude/agents/` contenant les 4 personas
  (correcteur, styliste, continuité, beta-lecteur).
- Un **worker** PHP tourne en tâche de fond, dépile les demandes d'agent une
  par une, clone le livre dans un dossier de travail isolé, lance
  `claude -p` dedans, puis calcule un diff par fichier modifié.
- Rien n'est appliqué sur le vrai fichier tant que tu n'as pas accepté le
  diff correspondant depuis l'interface.

Voir le détail d'architecture dans la spec (discutée avant le dev).

## Prérequis sur le serveur

- PHP 8.1+ avec l'extension `pdo_sqlite` (incluse par défaut dans la plupart
  des distributions)
- Le binaire système `diff` (paquet `diffutils`, présent sur ~toutes les
  distros Linux)
- Le [CLI Claude Code](https://code.claude.com) installé et authentifié via
  `claude login` (ou `claude setup-token` pour un jeton non-interactif — voir
  plus bas), lié à ton abonnement Claude.ai
- Un serveur web (Nginx + PHP-FPM, ou Apache + mod_php) — voir
  `deploy/nginx.conf.example`
- `systemd` pour faire tourner le worker en service (ou n'importe quel
  superviseur de process équivalent)

## Installation

```bash
git clone <ce-repo> /srv/book-manager
cd /srv/book-manager

cp .env.example .env
php -r 'echo password_hash("ton-mot-de-passe", PASSWORD_DEFAULT), "\n";'
# colle le résultat dans APP_PASSWORD_HASH= du .env

# Authentifie le CLI Claude Code sur ce serveur avec ton abonnement,
# puis génère un jeton longue durée pour l'usage non-interactif :
claude login
claude setup-token
# colle le jeton affiché dans CLAUDE_CODE_OAUTH_TOKEN= du .env
```

Configure ton serveur web pour pointer sur `public/` (voir
`deploy/nginx.conf.example`), puis installe le service du worker :

```bash
sudo cp deploy/book-manager-worker.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now book-manager-worker
sudo systemctl status book-manager-worker
```

La base SQLite et les dossiers de données (`data/`) sont créés
automatiquement au premier appel — rien à migrer à la main.

## Développement local

```bash
php -S 127.0.0.1:8899 -t public   # sert l'app
php worker/worker.php              # dans un second terminal, traite les runs d'agents
```

Le mot de passe local se configure de la même façon que ci-dessus (via
`.env` + `APP_PASSWORD_HASH`).

⚠️ Sans CLI `claude` installé/authentifié en local, les runs d'agents restent
bloqués en `pending`. Pour tester le pipeline sans dépendre du vrai CLI (ni de
ton abonnement), tu peux pointer `CLAUDE_BINARY` du `.env` vers n'importe quel
exécutable qui lit son dernier argument comme prompt et écrit du JSON par
ligne sur stdout — utile uniquement pour du test local.

## Sauvegarde

Tout ce qui compte vit dans `data/` (base SQLite + fichiers des livres).
Une sauvegarde simple :

```bash
tar czf backup-$(date +%F).tar.gz data/
```

## Structure du dépôt

```
public/              front controller PHP + assets statiques (CSS/JS/PWA)
src/                 code applicatif (contrôleurs, modèles, services)
worker/              daemon qui exécute les runs d'agents
agents-templates/    personas Claude Code copiés dans chaque nouveau livre
migrations/          schéma SQLite
deploy/              exemples systemd / nginx / .env
data/                (généré, ignoré par git) base SQLite + livres + runs
```
