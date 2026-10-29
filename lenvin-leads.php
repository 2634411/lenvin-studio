<?php
/**
 * Plugin Name: Lenvin Demo Leads
 * Description: Demonstration contact form and private lead inbox. Not a CRM integration.
 * Version: 1.0.0
 * License: GPL-2.0-or-later
 */
if (!defined('ABSPATH')) { exit; }
add_action('init', function () {
    register_post_type('lenvin_lead', ['label'=>'Демо-заявки','public'=>false,'show_ui'=>true,'show_in_rest'=>false,'supports'=>['title'],'capability_type'=>'post','map_meta_cap'=>true,'capabilities'=>['create_posts'=>'do_not_allow'],'menu_icon'=>'dashicons-email']);
});
function lenvin_validate($data) {
    $name = sanitize_text_field($data['name'] ?? '');
    $phone = preg_replace('/\D/', '', (string)($data['phone'] ?? ''));
    if (!$name || strlen($name)>240 || !preg_match('/^[78]\d{10}$/', $phone) || ($data['consent'] ?? false)!==true) {
        return new WP_Error('invalid_lead','Проверьте имя, телефон и подтверждение.', ['status'=>422]);
    }
    return ['name'=>$name,'phone'=>$phone];
}
add_action('rest_api_init', function () {
    register_rest_route('lenvin-demo/v1','/leads', [
        'methods'=>'POST',
        // This is a private test environment; visitors use a disposable Playground admin session.
        'permission_callback'=>function($request) {
            return current_user_can('manage_options') && wp_verify_nonce($request->get_header('X-WP-Nonce'),'wp_rest');
        },
        'callback'=>function($request) {
            $clean=lenvin_validate($request->get_json_params() ?: []);
            if (is_wp_error($clean)) return $clean;
            $key=sanitize_text_field($request->get_header('Idempotency-Key'));
            if (!preg_match('/^[a-zA-Z0-9-]{16,80}$/',$key)) return new WP_Error('invalid_key','Повторите отправку.', ['status'=>400]);
            $cache='lenvin_demo_'.hash('sha256',get_current_user_id().$key);
            if ($existing=get_transient($cache)) return new WP_REST_Response(['id'=>$existing,'duplicate'=>true],200);
            $id=wp_insert_post(['post_type'=>'lenvin_lead','post_status'=>'private','post_title'=>$clean['name'],'meta_input'=>['_lenvin_phone'=>$clean['phone']]],true);
            if (is_wp_error($id)) return new WP_Error('save_failed','Не удалось сохранить заявку.', ['status'=>500]);
            set_transient($cache,$id,HOUR_IN_SECONDS);
            return new WP_REST_Response(['id'=>$id,'duplicate'=>false],201);
        }
    ]);
});
add_filter('manage_lenvin_lead_posts_columns',function($cols){$cols['phone']='Телефон';return $cols;});
add_action('manage_lenvin_lead_posts_custom_column',function($col,$id){if($col==='phone') echo esc_html(get_post_meta($id,'_lenvin_phone',true));},10,2);
add_shortcode('lenvin_demo_form', function () {
    if (!current_user_can('manage_options')) return '<p>Откройте демонстрацию через Playground с тестовым входом.</p>';
    ob_start(); ?>
    <style>.lv-demo{font:17px/1.6 system-ui;max-width:1050px;margin:40px auto;padding:25px;color:#173c36}.lv-grid{display:grid;grid-template-columns:1fr 1fr;gap:40px}.lv-demo h1{font-size:clamp(36px,6vw,65px);line-height:1.05}.lv-demo form{background:#f3f4ee;padding:28px;border-radius:10px}.lv-demo label{display:block;margin:16px 0}.lv-demo input:not([type=checkbox]){width:100%;box-sizing:border-box;padding:12px;font:inherit}.lv-demo button,.lv-demo a{font:inherit}.lv-demo button{background:#173c36;color:white;padding:14px;border:0;border-radius:4px;cursor:pointer}.lv-demo button:disabled{opacity:.6}.lv-demo small{display:block;font-size:13px}.lv-demo #lv-result{min-height:50px;margin:15px 0}@media(max-width:700px){.lv-grid{grid-template-columns:1fr}}</style>
    <section class="lv-demo"><small>WORDPRESS · СОБСТВЕННЫЙ ПЛАГИН · ДЕМО</small><div class="lv-grid"><div><h1>Заявка на сайте.<br>Результат в админке.</h1><p>Проверьте форму, затем откройте список заявок WordPress.</p><p><a href="<?php echo esc_url(admin_url('edit.php?post_type=lenvin_lead')); ?>">Открыть демо-заявки →</a></p><small>Только вымышленные данные. Битрикс24, письма и платежи не подключены. У каждого посетителя отдельная тестовая среда Playground.</small></div><form id="lv-form"><label>Имя<input name="name" required maxlength="80" value="Тестовый клиент"></label><label>Телефон<input name="phone" type="tel" required value="+7 000 000-00-00"></label><label><input name="consent" type="checkbox" required> Использую только вымышленные данные</label><button>Отправить тестовую заявку</button><div id="lv-result" role="status" aria-live="polite"></div></form></div></section>
    <script>(()=>{const f=document.getElementById('lv-form'),s=document.getElementById('lv-result'),b=f.querySelector('button');let key=crypto.randomUUID();f.addEventListener('input',()=>{key=crypto.randomUUID()});f.addEventListener('submit',async e=>{e.preventDefault();const d={name:f.elements.name.value.trim(),phone:f.elements.phone.value,consent:f.elements.consent.checked};if(!/^[78]\d{10}$/.test(d.phone.replace(/\D/g,''))){s.textContent='Проверьте телефон: 11 цифр, начинается с 7 или 8.';return}b.disabled=true;s.textContent='Сохраняем…';try{const r=await fetch(<?php echo wp_json_encode(rest_url('lenvin-demo/v1/leads')); ?>,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-WP-Nonce':<?php echo wp_json_encode(wp_create_nonce('wp_rest')); ?>,'Idempotency-Key':key},body:JSON.stringify(d)});const j=await r.json();if(!r.ok)throw Error(j.message||'Ошибка сервера');s.textContent='Заявка №'+j.id+' сохранена в WordPress. Откройте список демо-заявок.'}catch(e){s.textContent=e.message}finally{b.disabled=false}})})();</script>
    <?php return ob_get_clean();
});
