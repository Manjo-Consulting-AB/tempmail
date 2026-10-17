<?php
/**
 * La FAQ en français (France) : la réponse sur les assistants IA et toute la page
 * sous forme de données (groupes, questions, réponses). Chargé par faq.php pour la
 * page en français. Reprend la structure de faq.en.php : les mêmes clés de groupe, le
 * même nombre de questions par groupe et les mêmes branches conditionnelles (période
 * d’essai activée/désactivée, texte avec connexion activé/désactivé) ; seuls les mots
 * changent.
 *
 * Variables que faq.php définit pour ce fichier :
 *   $msU            closure : chemin d’une page publique -> son URL échappée, avec la langue
 *   $msT            durée de la période d’essai en jours (0 = pas de période d’essai)
 *   $msDomain       le domaine de messagerie, déjà échappé pour le HTML
 *   $msMcpUrl       l’URL du endpoint MCP, déjà échappée pour le HTML
 *   $msMcpSignIn    si un client peut se connecter via une connexion (OAuth activé)
 *   $msHoldDays     durée de la pause de suppression en jours
 *   $msHoldMax      pauses de suppression par an
 *
 * 'a' contient le HTML interne d’une réponse : du balisage écrit ici, jamais rien de ce
 * qu’un visiteur fournit. Les ids de groupe (les clés du tableau) restent en anglais dans
 * toutes les langues pour que les #ancres continuent de fonctionner.
 *
 * @return array<string, array{title: string, items: list<array{q: string, a: string}>}>
 */

if (!defined('TEMPMAIL_APP')) {
    http_response_code(403);
    exit;
}

// La réponse sur les assistants IA, sous la forme qui correspond à l’interrupteur
// (#331, étape 6). Les deux sont réservées à Pro, s’arrêtent toutes deux à la liste
// d’outils du brief §9 et ne mentionnent ni prix ni date ; celle avec connexion ne
// s’affiche que lorsque OAuth est réellement activé. La phrase sur le fournisseur reste
// dans les deux : une application approuvée est un choix de l’utilisateur, pas notre
// sous-traitant.
$msAiSignInAnswer = <<<HTML
<p>Oui, avec Pro. Ajoute <code>{$msMcpUrl}</code> dans ton application et connecte-toi quand elle te le demande — Mail Shield te montre ce que l’application veut et tu l’approuves. Un client qui n’accepte qu’un en-tête utilise à la place l’un de tes jetons d’accès. Dans les deux cas, l’assistant se connecte via le Model Context Protocol (MCP) et peut alors lister tes adresses, lire tes e-mails et, si tu lui as donné un accès en écriture, créer et supprimer des adresses à ta place.</p>
<p>L’assistant agit comme ton compte, alors traite ce que tu lui donnes comme un mot de passe. Tout ce qu’il lit est envoyé à celui qui l’exploite : ce fournisseur, c’est toi qui le choisis, pas nous, et nous ne sommes pas son sous-traitant. Déconnecte une application, ou révoque un jeton, dans la carte Applications connectées de ton profil et elle cesse de fonctionner immédiatement ; changer ton mot de passe ou ton adresse e-mail coupe toutes les connexions d’un coup.</p>
HTML;
$msAiTokenAnswer = <<<HTML
<p>Oui, avec Pro. Tu crées un jeton d’accès dans ton profil et tu le donnes à l’assistant, qui se connecte à Mail Shield via le Model Context Protocol (MCP) à l’adresse <code>{$msMcpUrl}</code>. Il peut alors lister tes adresses, lire tes e-mails et, si tu as donné au jeton un accès en écriture, créer et supprimer des adresses à ta place.</p>
<p>L’assistant agit comme ton compte, alors traite son jeton d’accès comme un mot de passe. Tout ce qu’il lit est envoyé à celui qui l’exploite : ce fournisseur, c’est toi qui le choisis, pas nous, et nous ne sommes pas son sous-traitant. Révoque un jeton depuis la carte Applications connectées de ton profil et il cesse de fonctionner immédiatement ; changer ton mot de passe ou ton adresse e-mail révoque tous les jetons d’un coup.</p>
HTML;
$msAiAnswer = $msMcpSignIn ? $msAiSignInAnswer : $msAiTokenAnswer;

/**
 * La page entière sous forme de données : six groupes, dans l’ordre de la page, chacun
 * avec ses questions.
 *
 * Le texte visible et les données structurées FAQPage sont générés à partir de ce seul
 * tableau, si bien que les réponses lues par un robot ne peuvent pas s’écarter de celles
 * lues par une personne.
 *
 * 'a' contient le HTML interne de la réponse (ce qui va dans .ms-prose), pas du texte
 * échappé : c’est du balisage écrit ici, jamais rien de ce qu’un visiteur fournit.
 */
return [
    'general' => [
        'title' => 'Général',
        'items' => [
            [
                'q' => 'Qu’est-ce que Mail Shield ?',
                'a' => <<<HTML
<p>Une boîte de réception à part pour les e-mails que tu ne veux pas dans ta boîte principale. Achats, newsletters, inscriptions, réponses de forums, e-mails automatiques et services que tu essaies seulement ont enfin un autre endroit où aller, et tu les lis tous au même endroit sur le web.</p>
<p>Tu as tes propres adresses — des adresses Timed qui se suppriment toutes seules, et des adresses Sticky que tu gardes. Ce n’est pas une page qui affiche une adresse jetable et t’oublie dès que tu fermes l’onglet : ici, une adresse appartient à un compte, donc une confirmation qui arrive une heure plus tard t’attend toujours.</p>
HTML,
            ],
            [
                'q' => 'Comment obtenir une adresse Timed ?',
                'a' => <<<HTML
<p>Crée un compte avec ta propre adresse e-mail, vérifie-la, puis crée l’adresse Timed depuis ta boîte de réception. Il n’y a aucun bouton sur la page d’accueil qui en délivre une de façon anonyme — une adresse a besoin d’un compte derrière elle, et c’est ce qui te permet de revenir chercher un message arrivé plus tard.</p>
HTML,
            ],
            [
                'q' => 'Combien de temps les messages et les adresses sont-ils conservés ?',
                'a' => <<<HTML
<p>Une adresse Timed sur un compte gratuit est supprimée automatiquement <strong>24 heures</strong> après sa création, et les messages qu’elle contient partent avec elle. Avec Pro, tu choisis la durée de chaque adresse Timed, de <strong>1 à 7 jours</strong>.</p>
<p>Les adresses Sticky ne fonctionnent pas avec un minuteur — elles restent jusqu’à ce que tu les supprimes.</p>
HTML,
            ],
            [
                'q' => 'Est-ce que ça coûte quelque chose ?',
                'a' => $msT > 0 ? <<<HTML
<p>Un compte gratuit ne coûte rien et ne demande aucune carte bancaire, et chaque nouveau compte commence avec <strong>{$msT} jours de Pro</strong> offerts. Ensuite, reste sur Pro avec une <a href="{$msU('/pricing.php')}">formule mensuelle, annuelle ou à paiement unique</a>, ou avec un code d’activation.</p>
HTML : <<<HTML
<p>Un compte gratuit ne coûte rien et ne demande aucune carte bancaire. Pro est une <a href="{$msU('/pricing.php')}">formule mensuelle, annuelle ou à paiement unique</a>, ou un code d’activation.</p>
HTML,
            ],
        ],
    ],
    'accounts' => [
        'title' => 'Comptes et formules',
        'items' => [
            [
                'q' => 'Quelle est la différence entre Free et Pro ?',
                'a' => <<<HTML
<p>Un compte gratuit couvre l’e-mail temporaire : une seule adresse Timed à la fois, supprimée 24 heures après sa création.</p>
<p>Pro ajoute le côté permanent du produit et l’automatisation — jusqu’à 10 adresses Sticky, une durée de vie des adresses Timed de 1 à 7 jours que tu choisis, le RSS, les webhooks, Pushover, les récapitulatifs par e-mail et l’Agent.</p>
HTML . ($msT > 0 ? <<<HTML
<p>Chaque nouveau compte a Pro pendant ses {$msT} premiers jours, pour que tu puisses tout essayer avant de décider.</p>
HTML : ''),
            ],
            ...($msT > 0 ? [[
                'q' => "Comment fonctionne la période d’essai Pro de {$msT} jours ?",
                'a' => <<<HTML
<p>Chaque nouveau compte a Pro pendant ses {$msT} premiers jours, à partir du moment où tu confirmes ton adresse e-mail. Aucune carte à saisir et rien à résilier.</p>
<p>La période d’essai n’est accordée qu’une fois par adresse e-mail. Si tu supprimes ton compte et te réinscris avec la même adresse, le compteur continue depuis ta première inscription — il ne repart pas de zéro.</p>
<p>Quand la période d’essai se termine, ton compte continue comme un compte gratuit. L’automatisation est désactivée, et les adresses Sticky — avec les e-mails qu’elles contiennent — sont supprimées 7 jours plus tard, alors déplace d’abord ce dont tu as encore besoin. Pour rester sur Pro, choisis l’une de nos <a href="{$msU('/pricing.php')}">formules</a>, ou active un code d’activation dans ton profil.</p>
HTML,
            ]] : []),
            [
                'q' => 'Combien d’adresses puis-je créer ?',
                'a' => <<<HTML
<p>Les adresses appartiennent à ton compte, pas à une session de navigateur. Chaque compte a une seule adresse Timed à la fois, et en créer une nouvelle remplace l’ancienne.</p>
<p>Les comptes Pro ont aussi jusqu’à 10 adresses Sticky, qui coexistent avec l’adresse Timed et livrent dans la même boîte de réception.</p>
HTML,
            ],
            [
                'q' => 'Comment créer un compte Pro ?',
                'a' => $msT > 0 ? <<<HTML
<p>Crée un compte — il démarre avec Pro pendant ses {$msT} premiers jours. Pour garder Pro ensuite, choisis une <a href="{$msU('/pricing.php')}">formule</a> une fois connecté, ou active un code d’activation dans ton profil ; dans les deux cas, le temps s’ajoute après la fin de la période d’essai. Les comptes se connectent avec un lien de connexion (lien magique) envoyé par e-mail, et peuvent en option définir un mot de passe dans leur profil.</p>
HTML : <<<HTML
<p>Crée un compte gratuit et choisis une <a href="{$msU('/pricing.php')}">formule</a> une fois connecté — ou inscris-toi avec un code d’activation : choisis Pro sur la page d’inscription et saisis le code. Les comptes se connectent avec un lien de connexion (lien magique) envoyé par e-mail, et peuvent en option définir un mot de passe dans leur profil.</p>
HTML,
            ],
            [
                'q' => 'Quels paramètres sont disponibles avec Pro ?',
                'a' => <<<HTML
<ul>
<li>Définir un mot de passe pour te connecter, en plus du lien envoyé par e-mail.</li>
<li>Choisir combien de temps durent les nouvelles adresses Timed, de 1 à 7 jours.</li>
<li>Créer, consulter et supprimer jusqu’à 10 adresses Sticky.</li>
<li>Activer ou désactiver l’authentification à deux facteurs, et gérer les navigateurs de confiance.</li>
<li>Conserver tes e-mails pendant {$msHoldDays} jours, jusqu’à {$msHoldMax} fois par an.</li>
<li>Configurer l’automatisation : RSS, webhooks et récapitulatifs par e-mail.</li>
</ul>
HTML,
            ],
            [
                'q' => 'Puis-je garder mes e-mails plus longtemps ?',
                'a' => <<<HTML
<p>Oui, avec Pro. Jusqu’à {$msHoldMax} fois par an, tu peux mettre le nettoyage en pause : les e-mails de tes adresses Sticky qui seraient sinon supprimés sont conservés {$msHoldDays} jours à partir du jour de leur arrivée, au lieu des 1 à 7 jours habituels. Lance une pause en choisissant {$msHoldDays} jours dans <strong>Profil &rarr; Durée par défaut</strong>.</p>
<p>Mettre fin à une pause plus tôt laisse en place les e-mails dont la durée avait déjà été prolongée. Les adresses Timed ne sont pas concernées — elles expirent toujours à l’heure prévue et emportent leurs messages avec elles. La limite de stockage de ta boîte mail s’applique toujours, et quand elle est atteinte, les e-mails les plus anciens sont supprimés en premier.</p>
HTML,
            ],
        ],
    ],
    'temporary' => [
        'title' => 'Adresses Timed et Sticky',
        'items' => [
            [
                'q' => 'Quelle est la différence entre une adresse Timed et une adresse Sticky ?',
                'a' => <<<HTML
<p>Une adresse Timed est la version Mail Shield de l’e-mail temporaire : elle est faite pour être jetée. Elle expire selon un minuteur auquel tu n’as pas à penser — 24 heures sur un compte gratuit, 1 à 7 jours avec Pro — et emporte ses messages avec elle.</p>
<p>Une adresse Sticky est une adresse que tu gardes : un nom lisible comme <span class="ms-addr">shopping@{$msDomain}</span> plutôt qu’une chaîne aléatoire, sans expiration, et c’est toi qui décides quand elle disparaît. Les deux types arrivent dans la même boîte de réception.</p>
HTML,
            ],
            [
                'q' => 'Puis-je utiliser Mail Shield pour des adresses permanentes ?',
                'a' => <<<HTML
<p>Oui — c’est à ça que servent les adresses Sticky. Un compte Pro peut en avoir jusqu’à 10, toutes permanentes au sens qui compte : elles n’expirent pas, et tu les supprimes quand tu n’en as plus besoin.</p>
<p>Chaque adresse utilise le domaine {$msDomain}, et chacune livre dans la même boîte de réception.</p>
HTML,
            ],
            [
                'q' => 'Combien de temps durent les adresses Sticky ?',
                'a' => <<<HTML
<p>Jusqu’à ce que tu les supprimes. Elles n’ont pas de minuteur d’expiration et ne sont pas touchées par le nettoyage qui supprime les adresses Timed. Supprimer une adresse depuis ton profil la retire, et elle ne reçoit plus d’e-mails à partir de ce moment.</p>
HTML,
            ],
        ],
    ],
    'automation' => [
        'title' => 'Automatisation',
        'items' => [
            [
                'q' => 'À quoi puis-je la connecter ?',
                'a' => <<<HTML
<p>Les comptes Pro peuvent faire suivre leurs e-mails de plusieurs façons :</p>
<ul>
<li>Un flux RSS privé, lisible dans n’importe quel lecteur de flux, protégé par un jeton — pour tout ton compte, ou pour une seule adresse Sticky.</li>
<li>Des webhooks qui envoient chaque message entrant en JSON (POST) vers un endpoint que tu contrôles.</li>
<li>Pushover, pour recevoir les e-mails entrants sous forme de notification sur ton téléphone.</li>
<li>Les récapitulatifs par e-mail, un résumé périodique de ce qui est arrivé.</li>
</ul>
<p>Si tu fais tourner ton propre serveur de messagerie, l’Agent est aussi là : tu gères les listes d’expéditeurs et les règles de filtrage dans Mail Shield, et l’Agent les applique à une boîte mail de ton serveur. Ces e-mails restent sur ton serveur — ils n’arrivent pas dans Mail Shield.</p>
<p>Tu peux aussi connecter un assistant IA à ton compte, pour qu’il lise et gère tes adresses à ta place. Cela a sa propre question plus bas.</p>
HTML,
            ],
            [
                'q' => 'Puis-je connecter un assistant IA à mon compte ?',
                'a' => $msAiAnswer,
            ],
            [
                'q' => 'L’automatisation est-elle incluse dans un compte gratuit ?',
                'a' => $msT > 0 ? <<<HTML
<p>Pendant les {$msT} premiers jours, oui — chaque nouveau compte démarre avec Pro, automatisation comprise. Après la période d’essai, le RSS, les webhooks, Pushover, les récapitulatifs et l’Agent font partie de Pro, et un compte gratuit couvre l’e-mail temporaire.</p>
HTML : <<<HTML
<p>Non. Le RSS, les webhooks, Pushover, les récapitulatifs et l’Agent font tous partie de Pro. Un compte gratuit couvre l’e-mail temporaire.</p>
HTML,
            ],
        ],
    ],
    'security' => [
        'title' => 'Sécurité et confidentialité',
        'items' => [
            [
                'q' => 'Le contenu de mes e-mails est-il privé ?',
                'a' => <<<HTML
<p>La réponse dépend du type d’adresse, alors il vaut mieux être précis. Une adresse Timed est accessible par son lien : celui qui détient ce lien peut lire la boîte de réception, et toute personne qui le trouve aussi. Considère une adresse Timed comme un endroit pour recevoir, pas pour garder un secret.</p>
<p>Les adresses Sticky sont différentes. Elles appartiennent à ton compte et ne peuvent être lues que lorsque tu es connecté en tant que propriétaire.</p>
<p>Dans les deux cas, n’envoie pas de données personnelles sensibles — banque, pièces d’identité, informations médicales — à une adresse que tu comptes jeter.</p>
HTML,
            ],
            [
                'q' => 'Comment les pièces jointes sont-elles gérées ?',
                'a' => <<<HTML
<p>Les pièces jointes sont stockées temporairement, et les liens de téléchargement sont signés et limités dans le temps, de sorte qu’un lien ne peut pas être deviné ni transmis indéfiniment. Les fichiers sont supprimés en même temps que les messages auxquels ils étaient joints.</p>
HTML,
            ],
            [
                'q' => 'Que protège l’authentification à deux facteurs (2FA) ?',
                'a' => <<<HTML
<p>La 2FA protège la connexion à ton compte par mot de passe : une fois la 2FA activée, te connecter avec ton mot de passe demande aussi un code à 6 chiffres issu d’une application d’authentification. Elle n’ajoute aucune étape aux liens de connexion envoyés par e-mail — un lien de connexion prouve déjà que tu as accès à la boîte de réception, ce qui est en soi un second facteur.</p>
HTML,
            ],
            [
                'q' => 'Quelles applications d’authentification sont prises en charge ?',
                'a' => <<<HTML
<p>Toute application d’authentification TOTP standard fonctionne, notamment 1Password, Authy et Google Authenticator. Configure-la depuis la section d’authentification à deux facteurs de ton profil, en scannant le code QR ou en saisissant la clé manuellement.</p>
HTML,
            ],
            [
                'q' => 'Que faire si je perds mon téléphone ou mon application d’authentification ?',
                'a' => <<<HTML
<p>Tu n’es pas bloqué. Connecte-toi comme d’habitude avec ton lien de connexion par e-mail (lien magique) — il fonctionne même avec la 2FA activée — puis désactive l’authentification à deux facteurs depuis ton profil. Tu pourras ensuite la reconfigurer sur un nouvel appareil, ou la laisser désactivée. Tu peux aussi utiliser l’un des codes de secours à usage unique affichés lors de l’activation, si tu les as enregistrés.</p>
HTML,
            ],
            [
                'q' => 'L’Agent est-il sûr ?',
                'a' => <<<HTML
<p>L’Agent tourne sur ton propre serveur, donc tes données restent sous ton contrôle. Il vérifie les webhooks signés RSA de Mail Shield avant d’agir dessus, et stocke les secrets dont il a besoin sous forme chiffrée plutôt qu’en clair. Le filtrage se fait sur ta machine, donc tes e-mails n’ont pas besoin de la quitter pour être triés.</p>
HTML,
            ],
        ],
    ],
    'troubleshooting' => [
        'title' => 'Dépannage',
        'items' => [
            [
                'q' => 'Je n’ai pas reçu un message attendu — que faire ?',
                'a' => <<<HTML
<p>Vérifie l’adresse complète que tu as donnée, partie locale et domaine. Pour une adresse Sticky, assure-toi d’être connecté au compte qui en est propriétaire. Ensuite, patiente un instant et actualise — l’e-mail est stocké dès que le serveur de l’expéditeur le remet, et la boîte de réception le récupère à sa prochaine vérification ou quand tu actualises, même si un expéditeur peut retarder ou retenter l’envoi. S’il n’arrive toujours pas, l’expéditeur l’a peut-être retardé ou rejeté.</p>
HTML,
            ],
            [
                'q' => 'Comment obtenir de l’aide ?',
                'a' => <<<HTML
<p>Les problèmes propres à un compte se traitent mieux depuis ton compte : <a href="/pro_login.php">connecte-toi</a> et utilise la page de contact, en indiquant l’adresse ou le message concerné. Cette FAQ est là pour répondre d’abord aux cas généraux.</p>
HTML,
            ],
        ],
    ],
];
