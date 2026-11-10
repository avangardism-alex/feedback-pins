=== Pinora – QA & Client Feedback ===

Vos clients épinglent leurs retours directement sur la page. Votre équipe voit tout, en direct, sur un seul tableau. Captures d'écran et Slack inclus. Gratuit, sans compte.

== Description ==

**Arrêtez de collecter les retours sur un site dans des fils d'e-mails sans fin.** Avec Pinora, vos clients cliquent sur l'élément dont ils veulent parler, écrivent une note, et une épingle numérotée reste sur la page. Chaque personne du projet voit les notes des autres, en direct, sur la page et sur un tableau partagé.

Conçu par [AVANGARDISM](https://avangardism.com/?utm_source=wordpress.org&utm_medium=plugin&utm_campaign=readme), une agence web qui l'utilise sur ses propres projets clients. Gratuit, pour de bon : sans compte, sans version premium, sans service externe.

= Comment ça marche =

1. Envoyez à votre client le lien d'invitation depuis **Outils > Pinora**.
2. Il saisit son prénom une seule fois, clique sur **+ Remarque** puis sur n'importe quel élément de n'importe quelle page.
3. Une capture d'écran est prise, l'élément est encadré, la note est enregistrée avec sa gravité.
4. Votre équipe voit l'épingle numérotée sur la page et la carte sur le tableau, en moins de 15 secondes, sans recharger.
5. Faites passer les cartes de *À faire* à *En cours* puis à *Terminé*, répondez, exportez.

= Fonctionnalités =

* **Des notes épinglées sur n'importe quel élément**, sur n'importe quelle page, avec la page, la section et la taille d'écran enregistrées automatiquement.
* **Captures d'écran automatiques** de ce que regardait la personne, avec l'élément encadré et le point de clic marqué.
* **Partage en temps réel** : toutes les personnes qui ont ouvert le lien voient les notes des autres, sur les pages et sur le tableau.
* **Tableau des retours** en trois colonnes, avec glisser-déposer, filtres par page, personne et gravité, et réponses.
* **Liens « Voir sur la page »** qui font défiler jusqu'à l'élément, ouvrent la note et font clignoter l'élément.
* **Notifications Slack** avec la capture et un lien direct, envoyées par votre propre serveur.
* **Export Markdown et JSON**, groupé par page et par section, prêt à coller dans un ticket ou un assistant IA.
* **Quatre niveaux de gravité** : bloquant, majeur, mineur, confort.
* **Aucun compte pour vos clients** : une clé partagée dans le lien suffit.
* **Léger** : les visiteurs qui n'ont jamais ouvert de lien de recette ne téléchargent que quelques centaines d'octets.
* **Compatible avec les caches de pages** : le mode recette est décidé dans le navigateur, pas sur le serveur.
* **Accessible** : utilisable au clavier seul (Tab jusqu'à un élément, Entrée pour l'épingler), annonces pour les lecteurs d'écran, contrastes WCAG AA, réduction des animations respectée.
* **Prêt pour la traduction**, français inclus.

= Vos données restent les vôtres =

Les notes sont stockées comme type de contenu privé dans votre propre base de données, les captures dans votre propre dossier `uploads`. Rien n'est jamais envoyé à AVANGARDISM. Le seul appel sortant est le webhook Slack facultatif, que vous configurez vous-même.

= Besoin d'un site web ? =

AVANGARDISM conçoit et développe des sites web, des boutiques en ligne et des logiciels métier, en partant des utilisateurs. [Parlons de votre projet](https://avangardism.com/contact?utm_source=wordpress.org&utm_medium=plugin&utm_campaign=readme).

== Installation ==

1. Installez l'extension depuis **Extensions > Ajouter**, ou téléversez le dossier `pinora` dans `/wp-content/plugins/`.
2. Activez-la.
3. Allez dans **Outils > Pinora** et copiez le lien d'invitation.
4. Envoyez-le à vos relecteurs. C'est tout.

Les administrateurs connectés disposent aussi d'un menu **Recette** dans la barre d'outils pour relire la page en cours ou ouvrir le tableau.

= Constantes facultatives =

À définir dans `wp-config.php` pour garder les secrets hors de la base de données :

`define( 'PINORA_KEY', 'votre-propre-cle' );`
`define( 'PINORA_SLACK_WEBHOOK', 'https://hooks.slack.com/services/…' );`

== Foire aux questions ==

= Mes clients ont-ils besoin d'un compte WordPress ? =

Non. Le lien d'invitation contient une clé partagée. Toute personne qui l'a peut lire et écrire des notes, personne d'autre. Vous pouvez générer une nouvelle clé à tout moment, ce qui désactive les liens envoyés auparavant.

= La clé est-elle visible dans les journaux du serveur ? =

Non. Elle circule dans le fragment de l'URL (`#pinora_key=…`), que les navigateurs n'envoient jamais au serveur, et elle est retirée de la barre d'adresse dès l'ouverture de la page. Ensuite, elle est transmise dans un en-tête HTTP.

= Où se trouve le tableau ? =

À l'adresse `/feedback-board/` de votre site. Si une vraie page utilise déjà cette adresse, la page est prioritaire : changez le chemin avec le filtre `pinora_board_path`.

= Est-ce que ça ralentit mon site ? =

Les visiteurs ordinaires reçoivent un minuscule chargeur intégré, rien de plus. La barre d'outils, ses styles et la bibliothèque de captures ne sont téléchargés que par les personnes qui ont ouvert un lien de recette.

= Certaines captures diffèrent légèrement de la page =

Les captures sont dessinées dans le navigateur par html2canvas, qui recrée la page à partir de son HTML et de son CSS. Certains effets (masques CSS, certaines images de fond, images d'un autre domaine) peuvent être simplifiés. La note est toujours positionnée sur l'élément lui-même, l'épingle reste donc exacte.

= Est-ce compatible avec les constructeurs de pages et les extensions de cache ? =

Oui. La barre d'outils se superpose à la page et ne dépend ni du thème ni du constructeur, et le mode recette est décidé dans le navigateur : les caches de pages complètes ne posent pas de problème.

= Puis-je la personnaliser ? =

Quelques filtres sont disponibles : `pinora_board_path`, `pinora_capability`, `pinora_sync_interval`, `pinora_notes_limit`, `pinora_note`, `pinora_slack_message`, ainsi que l'action `pinora_note_created`.

= Que se passe-t-il si je supprime l'extension ? =

La supprimer depuis l'écran Extensions efface toutes les notes, toutes les captures et tous les réglages. La désactiver conserve tout.

== Services externes ==

Cette extension peut envoyer des notifications à **Slack**, uniquement si vous saisissez l'URL d'un webhook entrant Slack dans **Outils > Pinora** (ou définissez `PINORA_SLACK_WEBHOOK`).

* Ce qui est envoyé : à la création d'une note, son texte, le prénom de l'auteur, le chemin de la page, le nom de la section, la largeur d'écran, la gravité, l'URL publique de sa capture et des liens vers votre site.
* Où : à l'URL du webhook que vous avez fournie, sur `hooks.slack.com`, directement depuis votre serveur.
* Quand : à chaque création de note, et lorsque vous cliquez sur « Enregistrer et envoyer un test ».
* Conditions d'utilisation de Slack : https://slack.com/terms-of-service — politique de confidentialité : https://slack.com/trust/privacy/privacy-policy

Aucune autre requête externe n'est effectuée. La bibliothèque de captures (html2canvas) est fournie avec l'extension et servie depuis votre site.

== Confidentialité ==

Les notes enregistrent le prénom saisi par la personne, le texte de la note, le chemin de la page, la taille d'écran et l'agent utilisateur du navigateur. Les captures peuvent montrer tout ce qui était affiché dans la page sur l'écran de la personne. Elles sont enregistrées dans `wp-content/uploads/pinora/` sous des noms aléatoires impossibles à deviner, pour que Slack puisse les afficher. Supprimez une note pour supprimer sa capture.

== Captures d'écran ==

1. Épinglez une note sur n'importe quel élément : la capture est prise pendant que vous écrivez.
2. Le tableau des retours : toutes les notes de l'équipe, en trois colonnes.
3. Les notes sur la page, avec la liste latérale et le détail d'une note.
4. La page des réglages : lien d'invitation, clé, Slack.

== Journal des modifications ==

= 1.0.0 =
* Première version publique.

== Avis de mise à jour ==

= 1.0.0 =
Première version publique.

== Crédits ==

Les captures sont dessinées avec [html2canvas](https://html2canvas.hertzen.com) 1.4.1 de Niklas von Hertzen, licence MIT. Source non minifiée : https://github.com/niklasvh/html2canvas/tree/v1.4.1
