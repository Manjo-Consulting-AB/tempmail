<?php
/**
 * La página de correo temporal en español (España): todo el artículo como datos. La carga
 * temporary-email.php para la página en español. Refleja la estructura de
 * temporary-email.en.php: los mismos ids de sección en el mismo orden, las mismas ramas
 * condicionales (periodo de prueba activado/desactivado) y las mismas variables; solo
 * cambian las palabras, y la página en español expone los mismos hechos y nada más
 * (brief §9).
 *
 * Variables que temporary-email.php define para este archivo:
 *   $msU              closure: ruta de una página pública -> su URL escapada y con idioma
 *   $msDocDomain      el dominio de correo, ya escapado para HTML
 *   $msDocTrialDays   duración del periodo de prueba en días (0 = sin periodo de prueba, y siempre 0 con la sesión iniciada)
 *   $msDocFreeHours   cuánto dura una dirección Timed en una cuenta gratuita, en horas
 *   $msDocProMinDays  la duración mínima de una dirección Timed que puede elegir una cuenta Pro, en días
 *   $msDocProMaxDays  la duración máxima de una dirección Timed que puede elegir una cuenta Pro, en días
 *   $msDocStickyMax   el máximo de direcciones Sticky
 *
 * 'lede', el 'html' de cada sección y las líneas de 'cta' contienen marcado escrito aquí,
 * nunca nada que aporte un visitante; 'title', 'description', 'headline' y 'heading' son
 * texto plano que la página escapa. Los ids de sección siguen en inglés en todos los
 * idiomas para que los #anclajes sigan funcionando; el índice se construye a partir de las
 * secciones. 'cta' coloca la primera llamada a la acción después de la sección indicada en 'after'.
 *
 * @return array{title: string, description: string, headline: string, lede: string, toc_label: string, toc_eyebrow: string, sections: list<array{id: string, heading: string, html: string}>, cta: array{after: string, line: string, end_line: string}}
 */

if (!defined('TEMPMAIL_APP')) {
    http_response_code(403);
    exit;
}

return [
    'title'       => 'Correo temporal: direcciones de email desechable gratis · Mail Shield',
    'description' => 'Una dirección de correo temporal para registros, descargas y servicios que solo necesitas un tiempo. Lee el correo en tu bandeja de entrada de Mail Shield y deja que se limpie solo.',
    'headline'    => 'Correo temporal',
    'lede'        => 'Algunos registros no merecen tu dirección real. Una dirección de correo temporal te da una desechable: léela en una bandeja de entrada web como es debido y deja que Mail Shield la limpie por ti.',
    'toc_label'   => 'En esta página',
    'toc_eyebrow' => 'En esta página',
    'sections'    => [
        [
            'id'      => 'what',
            'heading' => '¿Qué es una dirección de correo temporal?',
            'html'    => <<<HTML
<p>Una dirección de correo temporal existe durante poco tiempo y después deja de existir. La usas allí donde prefieres no dar la que de verdad lees (una prueba gratuita, una descarga, un foro), y todo lo que se envíe a ella llega a una bandeja de entrada web que puedes abrir al instante.</p>
<p>Su otro nombre es email desechable. La dirección está pensada para tirarla, así que nunca tiene que ser fácil de recordar ni estar ordenada. Ese es el objetivo: tu bandeja de entrada principal conserva el correo que importa, y el resto va a un sitio que no tienes que cuidar.</p>
<p>En Mail Shield esto se llama una <strong>dirección Timed</strong>: una dirección que funciona con un reloj y se elimina sola, con todo lo que contiene, cuando el reloj se agota.</p>
HTML,
        ],
        [
            'id'      => 'get',
            'heading' => 'Cómo conseguir una dirección de correo temporal',
            'html'    => <<<HTML
<p>En esta página no hay ningún botón que te genere una dirección, y es a propósito: una bandeja de entrada de Mail Shield necesita una cuenta detrás.</p>
<ol>
    <li>Regístrate con tu propia dirección de correo y una contraseña. Se usa para verificar la cuenta y para contactar contigo sobre ella, nada más.</li>
    <li>Verifícala e inicia sesión.</li>
    <li>Crea una dirección Timed desde tu bandeja de entrada. Está lista para pegarla en aquello a lo que te estés registrando.</li>
</ol>
<p>Ese minuto extra te da algo que un generador de direcciones desechables no puede ofrecer. La dirección vive dentro de una bandeja de entrada real y persistente, así que puedes volver a por una confirmación que llegue una hora más tarde o encontrar el mensaje que explica cómo darte de baja. Una página que muestra una dirección y se olvida de ti en cuanto cierras la pestaña no puede hacer ninguna de las dos cosas.</p>
HTML,
        ],
        [
            'id'      => 'lifetime',
            'heading' => '¿Cuánto dura una dirección temporal?',
            'html'    => <<<HTML
<p>Depende de tu cuenta, y las dos cifras son exactas.</p>

<dl class="ms-doc__retention">
    <div class="ms-doc__tier">
        <dt class="ms-eyebrow">Gratis</dt>
        <dd class="ms-doc__tier-value">{$msDocFreeHours} horas</dd>
    </div>
    <div class="ms-doc__tier">
        <dt class="ms-eyebrow">Pro</dt>
        <dd class="ms-doc__tier-value">{$msDocProMinDays}&ndash;{$msDocProMaxDays} días</dd>
    </div>
</dl>

<p>Una cuenta gratuita conserva una dirección Timed durante {$msDocFreeHours} horas. Con Pro eliges para cada dirección una duración de {$msDocProMinDays} a {$msDocProMaxDays} días. Cuando el reloj se agota, la dirección y todo lo que contiene se eliminan automáticamente.</p>
HTML,
        ],
        [
            'id'      => 'when-to-use',
            'heading' => 'Cuándo usar una dirección de correo temporal',
            'html'    => <<<HTML
<p>El correo temporal da lo mejor de sí cuando la dirección es lo único de lo que no estás seguro.</p>
<ul>
    <li>Pruebas gratuitas de herramientas que quizá no vuelvas a abrir.</li>
    <li>Una descarga puntual que pide una dirección antes de empezar.</li>
    <li>Registros en foros y comunidades.</li>
    <li>Anuncios en marketplaces y webs de clasificados, donde prefieres no publicar la dirección que usas de verdad.</li>
    <li>Probar tus propios correos de registro y confirmación.</li>
</ul>
HTML,
        ],
        [
            'id'      => 'when-not-to-use',
            'heading' => 'Cuándo no usarla',
            'html'    => <<<HTML
<p>Es la herramienta equivocada para cualquier cosa que importe.</p>
<ul>
    <li>Banca, servicios de la administración o cualquier cosa vinculada a tu identidad.</li>
    <li>Cuentas que necesitarías recuperar: una vez que la dirección desaparece, también lo hace el restablecimiento de la contraseña.</li>
    <li>Cualquier cosa que pienses conservar.</li>
</ul>
<p>Algo que conviene decir claramente: una bandeja de entrada temporal a la que accedes mediante un enlace compartido no es privada. Si pasas el enlace, quien lo reciba puede leer el correo. Mail Shield prefiere decírtelo a dejar que supongas lo contrario, así que trata una dirección Timed como un sitio donde recibir, no donde guardar un secreto.</p>
HTML,
        ],
        [
            'id'      => 'permanent',
            'heading' => '¿Correo desechable o una dirección Sticky?',
            'html'    => <<<HTML
<p>Las direcciones Timed son solo una mitad de la historia. Mail Shield ofrece además a las cuentas Pro hasta {$msDocStickyMax} direcciones Sticky (direcciones permanentes que no caducan) como <span class="ms-addr">compras@{$msDocDomain}</span>, <span class="ms-addr">noticias@{$msDocDomain}</span> o <span class="ms-addr">proyectos@{$msDocDomain}</span>, para los remitentes de los que volverás a tener noticias. Las direcciones no caducan. Tú eliges cuándo eliminarlas, mientras que el correo que reciben se sigue limpiando según tus ajustes de conservación.</p>
<p>Si quieres un correo desechable en el que no tener que volver a pensar, una dirección Timed es la elección adecuada. Si has estado dando tu dirección real porque no había otro sitio donde poner un boletín, una dirección Sticky es la mejor solución. <a href="{$msU('/')}">La página de inicio te da la visión completa</a>.</p>
HTML,
        ],
    ],
    'cta' => [
        'after'    => 'lifetime',
        'line'     => $msDocTrialDays > 0
            ? 'Lleva aproximadamente un minuto, y tus primeros ' . $msDocTrialDays . ' días incluyen Pro, gratis.'
            : 'Lleva aproximadamente un minuto y es gratis.',
        'end_line' => 'Tu primera dirección Timed está a un minuto.',
    ],
];
