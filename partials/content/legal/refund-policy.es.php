<?php
/**
 * The refund policy in Spanish: the whole document as data, rendered by
 * partials/legal_page.php. Loaded by refund-policy.php for the Spanish page.
 * The Spanish file mirrors the English one: the same section ids in the same
 * order, the same conditional branches and the same facts; only the words
 * differ. Both carry the same 'updated' date (ISO Y-m-d; legal_page.php
 * renders it with i18nDate()).
 *
 * Variables refund-policy.php defines for this file:
 *   $f     array    the legal facts (partials/legal_facts.php), already escaped
 *   $ref   array    the invite programme's settings (referralSettings())
 *   $msU   closure  a public page path -> its escaped, language-aware URL
 * and defines $guaranteeDays and $graceDays itself.
 *
 * 'html' holds trusted markup authored here with every interpolated value
 * escaped, never anything a visitor supplies. Never write a number into the
 * text: it comes from $f or $ref.
 *
 * @return array<string, mixed>
 */

if (!defined('TEMPMAIL_APP')) {
    http_response_code(403);
    exit;
}

// How long the money-back guarantee lasts, and the grace before a lapsed Pro
// account's Sticky addresses are deleted (cron/cleanup.php). Fixed by the code,
// not by $config; named here so no number is typed into the prose.
$guaranteeDays = 30;
$graceDays = 7;

return [
    'title'       => 'Política de reembolso',
    'lede'        => 'Si Mail Shield Pro no es lo que buscabas, puedes recuperar tu dinero dentro de los ' . $guaranteeDays . ' días siguientes al pago. Sin preguntas.',
    'description' => 'Mail Shield ofrece una garantía de devolución del dinero de ' . $guaranteeDays . ' días en las suscripciones Pro y en las compras Lifetime. Los reembolsos los emite Paddle, nuestro revendedor.',
    'path'        => '/refund-policy.php',
    'updated'     => '2026-10-10',
    'sections'    => [
        [
            'id'      => 'guarantee',
            'heading' => 'Garantía de devolución del dinero de ' . $guaranteeDays . ' días',
            'html'    => '<p>Todo pago de Mail Shield Pro puede reembolsarse íntegramente si lo pides dentro de los ' . $guaranteeDays . ' días siguientes a la fecha en que se te cobró. Esto se aplica a:</p>'
                . '<ul>'
                . '<li>el primer pago de una suscripción mensual o anual;</li>'
                . '<li>cada renovación de una suscripción, contando desde la fecha de esa renovación;</li>'
                . '<li>una compra Lifetime de pago único.</li>'
                . '</ul>'
                . '<p>No necesitas dar ningún motivo. El reembolso es por el importe completo que pagaste, impuestos incluidos.</p>'
                . '<p>Esta garantía se suma a tus derechos legales como consumidor, incluido cualquier derecho de desistimiento, que Paddle gestiona como Merchant of Record (comerciante registrado) conforme a sus términos de compra. No los sustituye ni los limita.</p>',
        ],
        [
            'id'      => 'how',
            'heading' => 'Cómo pedir un reembolso',
            'html'    => '<p>Nuestro proceso de pedidos lo gestiona nuestro revendedor en línea Paddle.com. Paddle.com actúa como Merchant of Record (comerciante registrado) de todos nuestros pedidos. Paddle atiende todas las consultas de atención al cliente y gestiona las devoluciones.</p>'
                . '<p>Puedes pedirlo de cualquiera de las dos maneras:</p>'
                . '<ul>'
                . '<li><strong>A través de Paddle</strong> — sigue el enlace del correo con tu recibo, o busca tu compra en <a href="https://paddle.net" rel="noopener">paddle.net</a>.</li>'
                . '<li><strong>A través de nosotros</strong> — escribe a ' . $f['contact'] . ' desde la dirección de tu cuenta, o desde la dirección que figura en el recibo, y trasladaremos la solicitud a Paddle.</li>'
                . '</ul>'
                . '<p>Los reembolsos vuelven al método de pago que usaste. Paddle suele procesarlos en unos pocos días laborables; el tiempo que tarde en aparecer el dinero depende de tu banco o del emisor de tu tarjeta.</p>',
        ],
        [
            'id'      => 'after',
            'heading' => 'Qué ocurre con tu cuenta',
            'html'    => '<p>Un reembolso termina el tiempo de Pro que cubría el pago, y una suscripción reembolsada se cancela para que no se renueve. Tu cuenta se mantiene, como cuenta Regular gratuita. Como con cualquier plan Pro que termina, las direcciones Sticky se eliminan ' . $graceDays . ' días después, con su correo, así que traslada antes todo lo que quieras conservar.</p>'
                . '<p>Un reembolso de una compra Lifetime termina Pro en la fecha del reembolso. Después se aplican los mismos ' . $graceDays . ' días antes de que se eliminen las direcciones Sticky.</p>'
                . '<p>Cualquier tiempo de Pro que tuvieras antes de la compra — el periodo de prueba gratuito o un código de canje — no se ve afectado por el reembolso.</p>'
                . '<p>Si se reembolsa o se reclama un contracargo de un pago hecho a través de una <a href="' . $msU('/terms.php#referrals') . '">invitación</a> antes de que se conceda la recompensa de quien invitó, es decir, ' . $ref['hold_days'] . ' días después del pago, no se concede la bonificación de quien invitó, y los meses de bonificación del amigo terminan con la suscripción reembolsada. Un reembolso posterior a que quien invitó haya recibido su recompensa no se la retira.</p>'
                . '<p>Un reembolso no es una solicitud de eliminar tus datos. Aparte de que termine Pro, tu cuenta y sus datos siguen las reglas de conservación habituales de nuestra <a href="' . $msU('/privacy.php#retention') . '">política de privacidad</a>. Para eliminarlos, elimina tu cuenta.</p>',
        ],
        [
            'id'      => 'cancel',
            'heading' => 'Cancelar sin reembolso',
            'html'    => '<p>Puedes cancelar una suscripción en cualquier momento con <strong>Gestionar facturación</strong> en tu perfil. Cancelar detiene las renovaciones futuras; conservas Pro hasta el final del periodo que has pagado. Si todavía estás dentro de los ' . $guaranteeDays . ' días siguientes al último pago y además quieres recuperar tu dinero, pide un reembolso como se describe más arriba.</p>',
        ],
        [
            'id'      => 'after-30',
            'heading' => 'Pasados ' . $guaranteeDays . ' días',
            'html'    => '<p>Pasados ' . $guaranteeDays . ' días, los pagos normalmente no se reembolsan. Tus derechos según la legislación imperativa de consumo no se ven afectados, y si algo salió mal por nuestra parte — un cobro duplicado, o un servicio que no funcionó como se describe — escríbenos a ' . $f['contact'] . ' y lo solucionaremos.</p>'
                . '<p>Si cerramos Mail Shield o terminamos tu cuenta por un motivo distinto de un incumplimiento de nuestros <a href="' . $msU('/terms.php') . '">términos y condiciones</a>, reembolsamos como se describe allí.</p>',
        ],
    ],
];
