<?php
/**
 * La page « email temporaire » en français (France) : tout l’article sous forme de
 * données. Chargé par temporary-email.php pour la page en français. Reprend la structure
 * de temporary-email.en.php : les mêmes ids de section dans le même ordre, les mêmes
 * branches conditionnelles (période d’essai activée/désactivée) et les mêmes variables ;
 * seuls les mots changent, et la page française énonce les mêmes faits et rien de plus
 * (brief §9).
 *
 * Variables que temporary-email.php définit pour ce fichier :
 *   $msU              closure : chemin d’une page publique -> son URL échappée, avec la langue
 *   $msDocDomain      le domaine de messagerie, déjà échappé pour le HTML
 *   $msDocTrialDays   durée de la période d’essai en jours (0 = pas de période d’essai, et toujours 0 une fois connecté)
 *   $msDocFreeHours   durée d’une adresse Timed sur un compte gratuit, en heures
 *   $msDocProMinDays  la durée minimale d’une adresse Timed qu’un compte Pro peut choisir, en jours
 *   $msDocProMaxDays  la durée maximale d’une adresse Timed qu’un compte Pro peut choisir, en jours
 *   $msDocStickyMax   le plafond d’adresses Sticky
 *
 * 'lede', le 'html' de chaque section et les lignes 'cta' contiennent du balisage écrit
 * ici, jamais rien de ce qu’un visiteur fournit ; 'title', 'description', 'headline' et
 * 'heading' sont du texte brut que la page échappe. Les ids de section restent en anglais
 * dans toutes les langues pour que les #ancres continuent de fonctionner ; la table des
 * matières est construite à partir des sections. 'cta' place le premier appel à l’action
 * après la section nommée dans 'after'.
 *
 * @return array{title: string, description: string, headline: string, lede: string, toc_label: string, toc_eyebrow: string, sections: list<array{id: string, heading: string, html: string}>, cta: array{after: string, line: string, end_line: string}}
 */

if (!defined('TEMPMAIL_APP')) {
    http_response_code(403);
    exit;
}

return [
    'title'       => 'Email temporaire — adresse email jetable gratuite · Mail Shield',
    'description' => 'Une adresse email temporaire pour les inscriptions, les téléchargements et les services dont tu n’as besoin que pour un temps. Lis les e-mails dans ta boîte de réception Mail Shield, puis laisse le nettoyage se faire automatiquement.',
    'headline'    => 'Email temporaire : adresse jetable',
    'lede'        => 'Certaines inscriptions ne méritent pas ta vraie adresse. Une adresse email temporaire t’en donne une jetable à la place — lis-la dans une vraie boîte de réception web, puis laisse Mail Shield la nettoyer pour toi.',
    'toc_label'   => 'Sur cette page',
    'toc_eyebrow' => 'Sur cette page',
    'sections'    => [
        [
            'id'      => 'what',
            'heading' => 'Qu’est-ce qu’une adresse email temporaire ?',
            'html'    => <<<HTML
<p>Une adresse temporaire existe pendant une courte durée, puis cesse d’exister. Tu l’utilises partout où tu préfères ne pas donner celle que tu lis vraiment — un essai gratuit, un téléchargement, un forum — et tout ce qui lui est envoyé arrive dans une boîte de réception web que tu peux ouvrir tout de suite.</p>
<p>Son autre nom, c’est l’adresse email jetable, ou le mail jetable. L’adresse est faite pour être jetée, elle n’a donc jamais besoin d’être mémorable ni propre. C’est tout l’intérêt : ta boîte principale garde les e-mails qui comptent, et le reste va dans un endroit dont tu n’as pas à t’occuper.</p>
<p>Dans Mail Shield, cela s’appelle une <strong>adresse Timed</strong> : une adresse qui suit un minuteur et se supprime d’elle-même, avec tout ce qu’elle contient, quand le temps est écoulé.</p>
HTML,
        ],
        [
            'id'      => 'get',
            'heading' => 'Comment obtenir une adresse email temporaire',
            'html'    => <<<HTML
<p>Il n’y a aucun bouton sur cette page qui affiche une adresse pour toi, et c’est voulu — une boîte de réception Mail Shield a besoin d’un compte derrière elle.</p>
<ol>
    <li>Inscris-toi avec ta propre adresse e-mail et un mot de passe. Elle sert à vérifier le compte et à te joindre à son sujet, rien d’autre.</li>
    <li>Vérifie-la, puis connecte-toi.</li>
    <li>Crée une adresse Timed depuis ta boîte de réception. Elle est prête à être collée dans ce pour quoi tu t’inscris.</li>
</ol>
<p>Cette minute de plus t’apporte ce qu’un générateur d’e-mail jetable ne peut pas offrir. L’adresse vit dans une vraie boîte de réception qui persiste, donc tu peux revenir chercher une confirmation arrivée une heure plus tard, ou retrouver le message qui explique comment résilier. Une page qui affiche une adresse et t’oublie dès que tu fermes l’onglet ne peut faire ni l’un ni l’autre.</p>
HTML,
        ],
        [
            'id'      => 'lifetime',
            'heading' => 'Combien de temps dure une adresse temporaire ?',
            'html'    => <<<HTML
<p>Cela dépend de ton compte, et les deux chiffres sont exacts.</p>

<dl class="ms-doc__retention">
    <div class="ms-doc__tier">
        <dt class="ms-eyebrow">Gratuit</dt>
        <dd class="ms-doc__tier-value">{$msDocFreeHours} heures</dd>
    </div>
    <div class="ms-doc__tier">
        <dt class="ms-eyebrow">Pro</dt>
        <dd class="ms-doc__tier-value">{$msDocProMinDays}&ndash;{$msDocProMaxDays} jours</dd>
    </div>
</dl>

<p>Un compte gratuit garde une adresse Timed pendant {$msDocFreeHours} heures. Avec Pro, tu choisis une durée de {$msDocProMinDays} à {$msDocProMaxDays} jours pour chaque adresse. Quand le temps est écoulé, l’adresse et tout ce qu’elle contient sont supprimés automatiquement.</p>
HTML,
        ],
        [
            'id'      => 'when-to-use',
            'heading' => 'Quand utiliser une adresse email jetable',
            'html'    => <<<HTML
<p>L’e-mail jetable est idéal quand l’adresse est la seule chose dont tu n’es pas sûr.</p>
<ul>
    <li>Des essais gratuits d’outils que tu n’ouvriras peut-être plus jamais.</li>
    <li>Un téléchargement ponctuel qui demande une adresse avant de démarrer.</li>
    <li>Les inscriptions à des forums et à des communautés.</li>
    <li>Des annonces sur des places de marché et des sites de petites annonces, où tu préfères ne pas publier l’adresse que tu utilises vraiment.</li>
    <li>Tester tes propres e-mails d’inscription et de confirmation.</li>
</ul>
HTML,
        ],
        [
            'id'      => 'when-not-to-use',
            'heading' => 'Quand ne pas en utiliser',
            'html'    => <<<HTML
<p>Ce n’est pas le bon outil pour tout ce qui compte.</p>
<ul>
    <li>La banque, les services administratifs, ou tout ce qui est lié à ton identité.</li>
    <li>Les comptes que tu devrais pouvoir récupérer — une fois l’adresse disparue, la réinitialisation du mot de passe disparaît avec elle.</li>
    <li>Tout ce que tu comptes garder.</li>
</ul>
<p>Une chose mérite d’être dite clairement : une boîte de réception temporaire à laquelle tu accèdes par un lien partagé n’est pas privée. Si tu transmets le lien, celui qui le reçoit peut lire les e-mails. Mail Shield préfère te le dire plutôt que de te laisser supposer le contraire, alors considère une adresse Timed comme un endroit pour recevoir, pas pour garder un secret.</p>
HTML,
        ],
        [
            'id'      => 'permanent',
            'heading' => 'Email temporaire ou adresse Sticky ?',
            'html'    => <<<HTML
<p>Les adresses Timed ne sont qu’une moitié de l’histoire. Mail Shield offre aussi aux comptes Pro jusqu’à {$msDocStickyMax} adresses Sticky — <span class="ms-addr">shopping@{$msDocDomain}</span>, <span class="ms-addr">news@{$msDocDomain}</span>, <span class="ms-addr">projects@{$msDocDomain}</span> — pour les expéditeurs dont tu auras de nouveau des nouvelles. Ces adresses n’expirent pas. Tu choisis quand les supprimer, tandis que les e-mails qu’elles reçoivent sont toujours nettoyés selon tes paramètres de conservation.</p>
<p>Si tu veux un mail jetable auquel tu n’as plus jamais à penser, une adresse temporaire Timed est le bon choix. Si tu as donné ta vraie adresse faute d’un autre endroit où mettre une newsletter, une adresse Sticky est la meilleure solution. <a href="{$msU('/')}">La page d’accueil donne le tableau complet</a>.</p>
HTML,
        ],
    ],
    'cta' => [
        'after'    => 'lifetime',
        'line'     => $msDocTrialDays > 0
            ? 'Cela prend environ une minute, et tes ' . $msDocTrialDays . ' premiers jours incluent Pro, gratuitement.'
            : 'Cela prend environ une minute, et c’est gratuit.',
        'end_line' => 'Ta première adresse Timed est à une minute de toi.',
    ],
];
