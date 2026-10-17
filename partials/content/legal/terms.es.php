<?php
/**
 * The terms of service in Spanish: the whole document as data, rendered by
 * partials/legal_page.php. Loaded by terms.php for the Spanish page.
 * The Spanish file mirrors the English one: the same section ids in the same
 * order, the same conditional branches (trial on/off) and the same facts; only
 * the words differ. Both carry the same 'updated' date (ISO Y-m-d;
 * legal_page.php renders it with i18nDate()).
 *
 * Paddle requires this page before it approves the domain, and requires it to
 * name Paddle as Merchant of Record: section "Payments" carries that wording.
 * Every feature and retention period named here is one the code enforces
 * (CLAUDE.md, brief section 9).
 *
 * Variables terms.php defines for this file:
 *   $f     array    the legal facts (partials/legal_facts.php), already escaped
 *   $ref   array    the invite programme's settings (referralSettings())
 *   $msU   closure  a public page path -> its escaped, language-aware URL
 *
 * 'html' holds trusted markup authored here with every interpolated value
 * escaped, never anything a visitor supplies. Never write a number into the
 * text: it comes from $f, $ref or the named constants below.
 *
 * @return array<string, mixed>
 */

if (!defined('TEMPMAIL_APP')) {
    http_response_code(403);
    exit;
}

// Numbers the code or these terms fix rather than $config: the Pro timed
// address range (index.php clamps address_ttl_days to 1-7), the grace before a
// lapsed Pro account's Sticky addresses go (cron/cleanup.php), the money-back
// window, the notice period, the length of the plan the invite programme
// rewards and the look-back of the liability cap. Named here so none is typed
// into the prose.
$proTtlMinDays = 1;
$proTtlMaxDays = 7;
$graceDays = 7;
$guaranteeDays = 30;
$noticeDays = 30;
$yearPlanMonths = 12;
$liabilityMonths = 12;

$trialLine = $f['trial_days'] > 0
    ? '<p>Cada cuenta nueva empieza con un periodo de prueba gratuito de Pro de ' . $f['trial_days'] . ' días, una sola vez por dirección de correo. No hace falta ningún dato de pago para el periodo de prueba, y este no se convierte por sí solo en un plan de pago: cuando termina, la cuenta continúa como una cuenta Regular gratuita, salvo que compres Pro.</p>'
    : '';

return [
    'title'       => 'Términos y condiciones',
    'lede'        => 'Estos términos son el acuerdo entre tú y nosotros cuando usas Mail Shield. Están escritos para que se lean, así que son breves donde se puede.',
    'description' => 'Los términos que se aplican cuando usas Mail Shield, incluidos los planes Pro vendidos a través de Paddle como Merchant of Record (comerciante registrado).',
    'path'        => '/terms.php',
    'updated'     => '2026-10-15',
    'sections'    => [
        [
            'id'      => 'who',
            'heading' => 'Quiénes somos',
            'html'    => '<p>Mail Shield está operado por ' . $f['company_full'] . ' ("nosotros", "nos"). Puedes ponerte en contacto con nosotros en ' . $f['contact'] . '.</p>'
                . '<p>Al crear una cuenta o usar el servicio aceptas estos términos. Si no los aceptas, por favor no uses Mail Shield.</p>',
        ],
        [
            'id'      => 'service',
            'heading' => 'El servicio',
            'html'    => '<p>Mail Shield es un entorno de correo secundario en el dominio ' . $f['domain'] . '. Recibe correo por ti; no es un servicio para enviar correo. Según tu plan, puedes:</p>'
                . '<ul>'
                . '<li>crear direcciones Timed (direcciones temporales que se eliminan solas) que reciben correo durante ' . $f['free_hours'] . ' horas en una cuenta gratuita, o durante ' . $proTtlMinDays . ' a ' . $proTtlMaxDays . ' días en Pro;</li>'
                . '<li>mantener hasta 10 direcciones Sticky (direcciones permanentes que no caducan) (solo Pro);</li>'
                . '<li>reenviar lo que llega a otros sistemas — RSS, webhooks, Pushover y correos de resumen (digest) (solo Pro).</li>'
                . '</ul>'
                . '<p>Una cuenta gratuita tiene una cuota de almacenamiento de ' . $f['quota_free_mb'] . ' MB y una cuenta Pro, de ' . $f['quota_pro_mb'] . ' MB; la cifra de Pro se aplica desde el primer día del periodo de prueba, porque una cuenta en periodo de prueba es una cuenta Pro. El correo dirigido a una dirección que no está vinculada a una cuenta tiene su propia cuota de ' . $f['quota_free_mb'] . ' MB. Cuando se alcanza una cuota, los mensajes más antiguos se eliminan automáticamente para dejar sitio a los nuevos, aunque la dirección a la que llegaron todavía no haya caducado.</p>'
                . '<p>La cuota que cuenta es la que se aplica en el momento en que llega un mensaje. Si un plan Pro termina y la cuenta supera entonces la cuota gratuita, se reduce hasta ella — empezando por los mensajes más antiguos — a medida que llega correo nuevo.</p>'
                . '<p>Las direcciones y las integraciones tienen límites de uso que las protegen a ellas y al servicio. Cuando se alcanza un límite, la dirección o la integración se pausa automáticamente durante un tiempo y se te avisa; consulta <a href="' . $msU('/privacy.php#automated-limits') . '">Límites automáticos</a>.</p>'
                . '<p>El correo se elimina automáticamente cuando una dirección caduca o se borra. Mail Shield no es un archivo: guarda en otro lugar una copia de todo lo que necesites conservar.</p>',
        ],
        [
            'id'      => 'account',
            'heading' => 'Tu cuenta',
            'html'    => '<p>Necesitas una cuenta para usar Mail Shield. Debes facilitar una dirección de correo real que controles, mantener tu contraseña en secreto y avisarnos si crees que otra persona tiene acceso a tu cuenta. Eres responsable de lo que ocurra con tu cuenta.</p>'
                . '<p>Debes tener al menos 16 años, o la edad de consentimiento digital del lugar donde vives si es superior.</p>'
                . '<p>Una cuenta Regular gratuita en la que nadie ha iniciado sesión durante ' . $f['delete_days'] . ' días se elimina, junto con sus direcciones y su correo. Enviamos un aviso por correo ' . ($f['delete_days'] - $f['warn_days']) . ' días antes de que ocurra. Las cuentas Pro no se eliminan por inactividad.</p>'
                . '<p>Puedes eliminar tu cuenta en cualquier momento desde tu perfil. Al eliminarla se borran tus direcciones y tu correo.</p>',
        ],
        [
            'id'      => 'acceptable-use',
            'heading' => 'Uso aceptable',
            'html'    => '<p>No puedes usar Mail Shield para:</p>'
                . '<ul>'
                . '<li>infringir la ley, o ayudar a otra persona a infringirla;</li>'
                . '<li>cometer fraude, suplantar a otras personas o eludir bloqueos, límites o verificaciones de maneras que perjudiquen al servicio con el que usas la dirección;</li>'
                . '<li>usar una dirección para recopilar contraseñas, datos de pago u otros datos obtenidos mediante phishing, malware u otros engaños, o para recibir los resultados de ese tipo de maniobras;</li>'
                . '<li>crear direcciones o cuentas de forma masiva para hacer spam, fraude u operaciones de cuentas falsas en otros lugares;</li>'
                . '<li>recibir material cuya posesión es ilegal, incluido el material de abuso sexual infantil;</li>'
                . '<li>atacar, sondear o sobrecargar Mail Shield o su infraestructura, o saltarte sus límites;</li>'
                . '<li>revender el servicio o dar acceso a él como producto propio sin nuestro permiso por escrito.</li>'
                . '</ul>'
                . '<p>Podemos suspender o cerrar una cuenta que incumpla estas normas, y eliminar el contenido relacionado con ella. Cuando la ley lo exija, colaboraremos con las autoridades.</p>',
        ],
        [
            'id'      => 'suitability',
            'heading' => 'Para qué no sirve Mail Shield',
            'html'    => '<p>No uses una dirección Timed para nada que necesites conservar o recuperar — banca, servicios públicos o una cuenta cuyo restablecimiento de contraseña llegaría a ella. Cuando la dirección caduca, también lo hace tu acceso a ese correo.</p>'
                . '<p>Una dirección Timed a la que se accede mediante un enlace compartido puede leerla cualquiera que tenga el enlace. Trátala como un lugar donde recibir correo, no como un lugar donde guardar un secreto.</p>'
                . '<p>No podemos garantizar que llegue todo mensaje que te envíen. Los remitentes pueden retrasar, rechazar o filtrar el correo antes de que nos llegue.</p>',
        ],
        [
            'id'      => 'plans',
            'heading' => 'Planes, periodo de prueba y códigos de canje',
            'html'    => '<p>Mail Shield tiene un plan Regular gratuito y un plan Pro de pago. Pro puede comprarse como suscripción mensual o anual, o una sola vez como Lifetime. Las funciones de Pro son las descritas en la <a href="' . $msU('/pricing.php') . '">página de precios</a> en el momento en que compras.</p>'
                . $trialLine
                . '<p>El tiempo de Pro también puede concederse con un código de canje. Un código de canje no tiene valor en efectivo y no puede cambiarse por dinero.</p>'
                . '<p>"Lifetime" significa mientras operemos Mail Shield, no durante tu vida ni la nuestra. Si alguna vez cerramos el servicio, te avisaremos con antelación tal como se describe en <a href="#termination">Fin del servicio</a>.</p>'
                . '<p>Cuando termina Pro, la cuenta pasa a ser una cuenta Regular gratuita. Sus direcciones Sticky se eliminan ' . $graceDays . ' días después, con su correo, y sus integraciones se detienen.</p>',
        ],
        [
            'id'      => 'payments',
            'heading' => 'Pagos y Paddle',
            'html'    => '<p>Nuestro proceso de pedidos lo gestiona nuestro revendedor en línea Paddle.com. Paddle.com actúa como Merchant of Record (comerciante registrado) de todos nuestros pedidos. Paddle atiende todas las consultas de atención al cliente y gestiona las devoluciones.</p>'
                . '<p>Eso significa que la compra en sí es un contrato entre tú y Paddle, conforme a <a href="https://www.paddle.com/legal/checkout-buyer-terms" rel="noopener">los términos de compra de Paddle</a>. Paddle cobra el pago, cobra y abona el impuesto sobre las ventas o el IVA que se aplique donde estés, y te envía el recibo. Los precios se muestran en tu moneda local, con ese impuesto incluido.</p>'
                . '<h3>Suscripciones</h3>'
                . '<p>Una suscripción mensual o anual se renueva automáticamente al final de cada periodo, al precio vigente en ese momento, hasta que la canceles. Puedes cancelarla en cualquier momento con <strong>Manage billing</strong> en tu perfil o a través de Paddle. Tras cancelar conservas Pro hasta el final del periodo que has pagado; después no se hace ningún cargo.</p>'
                . '<p>Si cambiamos el precio de una suscripción, te lo comunicamos por correo antes de que el cambio se aplique a tu próxima renovación, para que puedas cancelar antes.</p>'
                . '<h3>Reembolsos</h3>'
                . '<p>Puedes recuperar tu dinero dentro de los ' . $guaranteeDays . ' días siguientes a un pago. Los detalles están en nuestra <a href="' . $msU('/refund-policy.php') . '">política de reembolso</a>.</p>',
        ],
        [
            'id'      => 'referrals',
            'heading' => 'Invitaciones',
            'html'    => '<p>Mientras el programa de invitaciones esté abierto, cada cuenta tiene un enlace de invitación personal, que se muestra en tu perfil. Las reglas son:</p>'
                . '<ul>'
                . '<li>Un amigo que crea una cuenta <strong>nueva</strong> a través de tu enlace, y cuya dirección de correo no se ha usado nunca antes con Mail Shield, queda vinculado a ti. El vínculo se establece una sola vez, cuando la cuenta se verifica por primera vez, y no puede cambiarse después.</li>'
                . '<li>Si ese amigo compra un plan Pro de ' . $yearPlanMonths . ' meses dentro de los ' . $ref['window_days'] . ' días siguientes a la creación de la cuenta, el amigo recibe ' . $ref['bonus_months'] . ' meses adicionales: su próximo pago se aplaza ' . $ref['bonus_months'] . ' meses. Si su suscripción está configurada para terminar, los meses se añaden a su tiempo de Pro.</li>'
                . '<li>Tú recibes ' . $ref['bonus_months'] . ' meses de Pro ' . $ref['hold_days'] . ' días después del pago del amigo, siempre que para entonces no se haya reembolsado ni se haya reclamado un contracargo. Si pagas con suscripción, tu próximo pago se aplaza ' . $ref['bonus_months'] . ' meses. En caso contrario, los meses se añaden a tu fecha de finalización actual, o a hoy si ya ha pasado. Si tienes Lifetime, recibes en su lugar ' . $ref['sticky_bonus'] . ' direcciones Sticky más, además del límite habitual, mientras la cuenta sea Pro.</li>'
                . '<li>Una compra Lifetime del amigo también cuenta para ti, pero no le da al amigo nada adicional.</li>'
                . '<li>No hay límite en el número de invitaciones, y las recompensas se acumulan. Las recompensas no tienen valor en efectivo y no pueden transferirse.</li>'
                . '<li>No está permitido invitarte a ti mismo, ni usar varias cuentas o datos de pago para conseguir recompensas. Podemos retener o retirar las recompensas obtenidas de ese modo.</li>'
                . '<li>Podemos modificar o terminar el programa. Las recompensas ya concedidas se mantienen.</li>'
                . '</ul>'
                . '<p>Lo que ocurre con una recompensa cuando se reembolsa un pago está en la <a href="' . $msU('/refund-policy.php#after') . '">política de reembolso</a>; lo que guardamos sobre una invitación está en la <a href="' . $msU('/privacy.php#data') . '">política de privacidad</a>.</p>',
        ],
        [
            'id'      => 'termination',
            'heading' => 'Fin del servicio',
            'html'    => '<p>Puedes dejar de usar Mail Shield y eliminar tu cuenta en cualquier momento. Cancelar una suscripción es un paso aparte, que se hace con Manage billing; eliminar la cuenta no la cancela por sí solo.</p>'
                . '<p>Podemos terminar o restringir tu uso de Mail Shield de tres maneras distintas:</p>'
                . '<ul>'
                . '<li><strong>Suspensión inmediata.</strong> Cuando detectamos un abuso, o un riesgo para la seguridad de Mail Shield o de sus usuarios, podemos suspender una cuenta de inmediato y sin previo aviso mientras lo investigamos.</li>'
                . '<li><strong>Cierre tras un incumplimiento.</strong> Si incumples estos términos, podemos cerrar tu cuenta.</li>'
                . '<li><strong>Decisión nuestra.</strong> Si terminamos tu cuenta por cualquier otro motivo, o cerramos Mail Shield por completo, te avisaremos por correo con al menos ' . $noticeDays . ' días de antelación y reembolsaremos la parte no utilizada de cualquier periodo de suscripción que hayas pagado. En el caso de una compra Lifetime realizada menos de dos años antes del cierre del servicio, reembolsamos una parte del precio proporcional a la parte de esos dos años que queda.</li>'
                . '</ul>',
        ],
        [
            'id'      => 'transfer',
            'heading' => 'Transmisión del servicio',
            'html'    => '<p>Podemos transmitir Mail Shield y estos términos a otro operador que se haga cargo del servicio. Te lo comunicaremos por correo antes de que ocurra. Tus derechos, Lifetime incluido, continúan frente al nuevo operador, y si no aceptas la transmisión puedes eliminar tu cuenta.</p>',
        ],
        [
            'id'      => 'liability',
            'heading' => 'Garantía y responsabilidad',
            'html'    => '<p>Trabajamos para que Mail Shield esté disponible y tu correo a salvo, pero el servicio se presta tal cual, y no prometemos que sea ininterrumpido ni que esté libre de errores.</p>'
                . '<p>En la medida en que la ley lo permita, no respondemos de pérdidas indirectas o consecuenciales, como la pérdida de beneficios, de datos o del acceso a una cuenta en otro lugar, y nuestra responsabilidad total frente a ti se limita a lo que hayas pagado por Mail Shield en los ' . $liabilityMonths . ' meses anteriores al momento en que surgió la reclamación.</p>'
                . '<p>Nada de lo dispuesto en estos términos limita los derechos que tienes como consumidor según la legislación imperativa, ni nuestra responsabilidad por negligencia grave o dolo.</p>',
        ],
        [
            'id'      => 'privacy',
            'heading' => 'Privacidad',
            'html'    => '<p>La forma en que tratamos los datos personales, incluido el correo que recibes, se describe en nuestra <a href="' . $msU('/privacy.php') . '">política de privacidad</a>.</p>',
        ],
        [
            'id'      => 'changes',
            'heading' => 'Cambios en estos términos',
            'html'    => '<p>Podemos actualizar estos términos. La fecha que aparece arriba indica cuándo cambiaron por última vez. Si un cambio afecta de forma sustancial a tus derechos, te enviaremos un correo al menos ' . $noticeDays . ' días antes de que entre en vigor. Si sigues usando Mail Shield después, se aplican los nuevos términos; si no los aceptas, puedes eliminar tu cuenta y cancelar tu suscripción antes de esa fecha.</p>',
        ],
        [
            'id'      => 'law',
            'heading' => 'Ley aplicable y controversias',
            'html'    => '<p>Estos términos se rigen por la ley sueca. Las controversias se resuelven ante los tribunales suecos, con el Tribunal de Distrito de Estocolmo (Stockholms tingsrätt) como primera instancia.</p>'
                . '<p>Si eres consumidor en la UE, también puedes acudir a la Junta Nacional Sueca de Reclamaciones de Consumo (Allmänna reklamationsnämnden, <a href="https://www.arn.se" rel="noopener">arn.se</a>). Por favor, ponte primero en contacto con nosotros en ' . $f['contact'] . ': la mayoría de las cosas se resuelven más rápido directamente.</p>',
        ],
    ],
];
