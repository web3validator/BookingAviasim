<?php

namespace App\Booking;

use Illuminate\Support\Facades\Log;

use function App\array_search_r;

class Booking
{
    public $currency = '';

    public function __construct()
    {
        $this->currency = \get_field('order_currency', 'option');
    }

    public function hook()
    {
        add_action('init', [$this, 'register']);
        add_action('rest_api_init', [$this, 'register_routes']);
        add_action('booking_check_order_pay_cron', [$this, 'check_order_pay'], 10, 1);
    }

    public function register()
    {
        register_post_type('booking-order', [
            'labels'              => [
                'name'          => 'Booking orders 2',
                'singular_name' => 'Booking order',
            ],
            'public'              => true,
            'exclude_from_search' => true,
            'show_ui'             => false,
            'show_in_nav_menus'   => false,
            'publicly_queryable'  => true,
            'query_var'           => true,
        ]);

        foreach (Order::$statuses as $status) {
            register_post_status($status, [
                'label'     => $status,
                'public'    => true,
                'post_type' => ['booking-order'],
                // Define one or more post types the status can be applied to.
            ]);
        }
    }

    public function register_routes()
    {
        register_rest_route('avia', 'order', [
            'methods'             => 'POST',
            'permission_callback' => '__return_true',
            'callback'            => [$this, 'order_callback'],
        ]);

        register_rest_route('avia', 'order/promocode', [
            'methods'             => 'POST',
            'permission_callback' => '__return_true',
            'callback'            => [$this, 'order_get_promocode_callback'],
        ]);

        register_rest_route('avia', 'order/get_dates', [
            'methods'             => 'GET',
            'permission_callback' => '__return_true',
            'callback'            => [$this, 'order_get_dates_callback'],
        ]);

        register_rest_route('avia', 'order/get_orders', [
            'methods'             => 'GET',
            'permission_callback' => function ($request) {
                return current_user_can('edit_posts');
            },
            'callback'            => [$this, 'get_orders_callback'],
        ]);

        register_rest_route('avia', 'order/liqpay', [
            'methods'             => 'POST',
            'permission_callback' => '__return_true',
            'callback'            => [$this, 'order_liqpay_callback'],
        ]);

        register_rest_route('avia', 'order/liqpay/(?P<id>[a-zA-Z0-9-]+)', [
            'methods'             => 'POST',
            'permission_callback' => '__return_true',
            'callback'            => [$this, 'order_liqpay_status_callback'],
            'args'                => [
                'id' => [
                    'required' => true,
                    'type'     => 'string',
                ],
            ],
        ]);
    }

    public function get_orders_callback(\WP_REST_Request $request): \WP_REST_Response
    {
        $params = $request->get_params();

        $args = [
            'posts_per_page' => $params['limit'] ?? -1,
        ];

        if (isset($params['status'])) {
            $args['post_status'] = $params['status'];
        }

        $meta_query = [];

        if (isset($params['gift'])) {
            $meta_query[] = [
                'key'   => 'booking_gift',
                'value' => '1',
            ];
        }

        if ( ! empty($meta_query)) {
            $args['meta_query'] = $meta_query;
        }

        $orders = Order::get_all($args);

        return new \WP_REST_Response($orders);
    }

    public function order_get_dates_callback(): \WP_REST_Response
    {
        $data = [
            'dates'     => self::available_dates(),
            'durations' => get_field('order_durations', 'option'),
        ];

        return new \WP_REST_Response($data);
    }

    public function order_liqpay_status_callback(\WP_REST_Request $request)
    {
        try {
            $liqpay = new LiqPay();

            $response = $liqpay->api('request', [
                'action'   => 'status',
                'version'  => '3',
                'order_id' => $request['id'],
            ]);

            $order            = Order::get($request['id']);
            $order->liqpay    = json_encode($response);
            $order->liqpay_ts = time();
            $order->save();

            return new \WP_REST_Response(1);
        } catch (\Throwable $th) {
            wp_die();
        }
    }

    private function send_email($order, $date, $time='')
    {
        try {
            $order = $order instanceof Order ? $order : Order::get($order);
            Log::info('sms_sent email', [ "sms_sent email", $order->sms_sent]);
            if ($order->sms_sent) {
                return;
            }
            Log::info('EMAIL');
            $mailgun = new MailgunMessage();
            $mailgun -> send_email_cert($order, $date, $time);

        } catch (\Exception $e) {
            Log::error('sent sms', [ "error", $e->getMessage()]);
            // print $e->getMessage();
        }
    }

    private function send_sms($order, $message, $message_admin)
    {

        $phone_admin1 = "380664052103";
        $phone_admin2 = "380672478996";

        try {
            $order = $order instanceof Order ? $order : Order::get($order);
            Log::info('sms_sent', [ "sms_sent", $order->sms_sent]);
            if ($order->sms_sent) {
                return;
            }

            $phone = preg_replace('/\D+/', '', $order->phone);

            $vodafone = new VodafoneAPI();
            $telegram_bot = new TelegramSend();
            $result  = $vodafone->sendMessage($phone, $message);
            // $vodafone->sendMessage($phone_admin1, $message_admin);
            // $vodafone->sendMessage($phone_admin2, $message_admin);
            $telegram_bot->telegram_send($message_admin);

            $order->sms_sent = $result;
            $order->save();
        } catch (\Exception $e) {
            Log::error('sent sms', [ "error", $e->getMessage()]);
            // print $e->getMessage();
        }
    }


    private function gift_send_sms($order)
    {

        try {
            $order = $order instanceof Order ? $order : Order::get($order);

            $bookingtype = "Тип не выбран";

            if ($order->bookingtype){
                $bookingtype  =$order->bookingtype;

            }
            // Log::info('Gift SMS Email');
            if ($order->delivery_address){
                // Log::info('Gift SMS Email Cart');
                $date = "Без дати";
                $time = '';
                if ($order->date){
                    $date  = date('d.m.Y', $order->date) . " o " . date('H:i', $order->date);
                }

                $message = "Шановний клієнте, дякуємо за  замовлення польоту на авіасимуляторі Боїнг 737!\n\nМи надішлемо вам карту найлижчим часом за адресою {$order->delivery_address}.\n\nЗ повагою, Команда Aviasim\nТел: +380507370800\n\nНа наш веб-сайт: https://aviasim.com.ua";

                if ($order->bookingtype == 'F18')
                    $message = "Шановний клієнте, дякуємо за  замовлення польоту на авіасимуляторі F/A-18 Hornet!\n\nМи надішлемо вам карту найлижчим часом за адресою {$order->delivery_address}.\n\nЗ повагою, Команда Aviasim\nТел: +380507370800\n\nНа наш веб-сайт: https://aviasim.com.ua";

                $message_admin = "Address: {$order->delivery_address}.\n\nКонтактно інформація:\nName: {$order->name}\nType: {$bookingtype}\nKey: {$order->key}\nEmail: {$order->email}\nPhone: {$order->phone}\nDate: {$date}\nDuration: {$order->duration} хв\nStatus: ✅ {$order->status}";


                $this->send_sms($order, $message, $message_admin);

                return ;

            }
            $date = "Без дати";
            $time = '';
            if ($order->date){
                $date  = date('d.m.Y', $order->date);
                $time  = date('H:i', $order->date);

            }


            $message = "Шановний клієнте, дякуємо за замовлення подарункового сертифікату на політ авіасимулятора Боїнг 737!\n\nМи надішлемо на вашу пошту повідомлення з сертифікатом.\n\nЗ повагою, Команда Aviasim\nТел: +380507370800\n\nВеб-сайт: https://aviasim.com.ua";

            if ($order->bookingtype == 'F18')
                $message = "Шановний клієнте, дякуємо за замовлення подарункового сертифікату на політ авіасимулятора F/A-18 Hornet!\n\nМи надішлемо на вашу пошту повідомлення з сертифікатом.\n\nЗ повагою, Команда Aviasim\nТел: +380507370800\n\nВеб-сайт: https://aviasim.com.ua";


            $message_admin = "Відправити сертифікат на електрону пошту.\n\nName: {$order->name}\nType: {$bookingtype}\nKey: {$order->key}\nEmail: {$order->email}\nPhone: {$order->phone}\nDate: $date $time\nDuration: {$order->duration} хв\nStatus: ✅ {$order->status}";

            // $this->send_email($order, $date, $time);
            $this->send_sms($order, $message, $message_admin);





        } catch (\Exception $e) {
            Log::error('Gift sent sms', [ "error", $e->getMessage()]);
            // print $e->getMessage();
        }
    }


    public function order_liqpay_callback(\WP_REST_Request $request)
    {


        if (
            ( ! isset($request['signature'])) || ( ! isset($request['data']))
        ) {
            wp_die();
        }

        $demo = get_field('liqpay_demo', 'option') ?? true;

        $private_key = $demo
            ? get_field('liqpay_private_key_demo', 'option')
            : get_field('liqpay_private_key', 'option');

        $sign = base64_encode(sha1($private_key.$request['data'].$private_key, 1));

        if ($sign !== $request['signature']) {
            wp_die();
        }

        $response = json_decode(base64_decode($request['data']), true);

        $order_id         = $response['order_id'];
        $order            = Order::get($order_id);
        $order->liqpay    = json_encode($response);
        $order->liqpay_ts = time();


        switch ($response['status']) {


            case 'success':


                $order->status = 'payment-success';

                if ($order->email != "bodo@bodo.ua") {

                    Log::info('DATA', [ "DATA", $order->date]);

                    if($order->gift){
                        $this->gift_send_sms($order);

                        break;
                    }


                    $date  = date('d.m.Y', $order->date);
                    $time  = date('H:i', $order->date);

                    $message = "Шановний клієнте, дякуємо за  замовлення польоту на авіасимуляторі Боїнг 737!\n\nЧекаємо Вас {$date}/{$time} за адресою: вул. Герцена, 35, (внутрішній двір, окремий вхід між 4-ю та 5-ю секціями.)\n\nДля відкриття шлагбауму №1 телефонуйте за номером:\n+380 67 804 8487.\nШлагбаум №2:\n+380 67 804 8493.\n\nБажаємо гарного відпочинку та приємних вражень!\n\nПосилання на адресу в GoogleMaps:\nhttps://goo.gl/maps/o7TPzXDCD3vKaUeD7\n\nНа наш веб-сайт:\nhttps://aviasim.com.ua";

                    if ($order->bookingtype == 'F18')
                        $message = "Шановний клієнте, дякуємо за  замовлення польоту на авіасимуляторі F/A-18 Hornet!\n\nЧекаємо Вас {$date}/{$time} за адресою: вул. Герцена, 35, (внутрішній двір, окремий вхід між 4-ю та 5-ю секціями.)\n\nДля відкриття шлагбауму №1 телефонуйте за номером:\n+380 67 804 8487.\nШлагбаум №2:\n+380 67 804 8493.\n\nБажаємо гарного відпочинку та приємних вражень!\n\nПосилання на адресу в GoogleMaps:\nhttps://goo.gl/maps/o7TPzXDCD3vKaUeD7\n\nНа наш веб-сайт:\nhttps://aviasim.com.ua";



                    $message_admin = "Name: {$order->name}\nType: {$order->bookingtype}\nKey: {$order->key}\nEmail: {$order->email}\nPhone: {$order->phone}\nDate: {$date} o {$time}\nDuration: {$order->duration} хв\nStatus: ✅ {$order->status}";



                    $this->send_sms($order, $message, $message_admin);
                }
                break;
            case 'error':
            case 'failure':

                $order->status = 'payment-failed';


        }
        Log::info("Fali SEND SMS", [
            'status' => $order->status,
        ]);

        $order->save();

        wp_die();
    }

    public function order_get_promocode_callback(\WP_REST_Request $request): \WP_REST_Response
    {
        $promocode = sanitize_text_field($request->get_body());
        $discount  = self::get_discount($promocode);

        return new \WP_REST_Response($discount);
    }

    public function order_callback(\WP_REST_Request $request): \WP_REST_Response
    {
        $errors = [];

        $has_gift      = isset($request['has_gift']) && $request['has_gift'] === 'yes';
        $is_gift       = isset($request['gift']) && $request['gift'] === 'yes';
        $is_fixed_date = isset($request['fixed_date']) && $request['fixed_date'] === 'yes';
        $delivery_gift = isset($request['delivery_gift']) && $request['delivery_gift'] === 'yes';

        if (isset($request['_text']) && ! empty($request['_text'])) {
            $errors[] = '<p>Caught!</p>';
        }

        $required_fields = ['name', 'phone', 'email', 'rules'];

        if ( ! $has_gift) {
            $required_fields[] = 'duration';
        } else {
            $required_fields[] = 'gift_code';
        }

        if ( ! $is_gift || $is_fixed_date) {
            $required_fields[] = 'date';
        }

        if ($delivery_gift) {
            $required_fields[] = 'address';
        }

        if (
            ! isset($request['phone'])
            || ! preg_match('/\+38 \(0\d{2}\) \d{3}-\d{2}-\d{2}/', $request['phone'])
        ) {
            $errors[] = [
                'field'   => 'phone',
                'message' => __('Неправильний формат телефону'),
            ];
        }

        foreach ($required_fields as $field_name) {
            if ( ! isset($request[$field_name]) || empty($request[$field_name])) {
                $errors[] = [
                    'field'   => $field_name,
                    'message' => sprintf('Поле <code>%s</code> обов&apos;язкове!', $field_name),
                ];
            }
        }

        $delivery_address = $delivery_gift ? sanitize_text_field($request['address']) : false;


        $duration = $has_gift ? 60
            : sanitize_text_field($request['duration']); // TODO get duration from gift code

        $type = sanitize_text_field($request['bookingtype']);
        $current_url = $_SERVER['REQUEST_URI'];
        if ($type === 'F18' || strpos($current_url, '/order-2') !== false || strpos($current_url, '/f18') !== false) {
            $price    = $has_gift ? 0 : self::get_price_2($duration); // / TODO get price from gift code
        } else {
            $price    = $has_gift ? 0 : self::get_price($duration); // / TODO get price from gift code
        }
        $date     = null;

        $bookingtype = sanitize_text_field($request['bookingtype']);

        if ($has_gift) {
            $giftCode = sanitize_text_field($request['gift_code']);

            global $wpdb;

            $query2 = $wpdb->prepare(
                "SELECT COUNT(*)
                 FROM {$wpdb->postmeta}
                 WHERE meta_key = 'booking_gift_code'
                 AND meta_value = %s",
                $giftCode
            );

            $count = $wpdb->get_var($query2);

            if ($count >= 2) {
                // Если gift code найден два или более раза, выдаем ошибку
                $errors[] = [
                    'field'   => 'gift_code',
                    'message' => __('Код сертифікату був вже використаний раніше.'),
                ];
            }

            function isGiftCodeValid($giftCode) {
                global $wpdb, $existingPostID;

                // Проверяем, существует ли запись с именем $giftCode в базе данных
                $query = $wpdb->prepare(
                    "SELECT p.ID
                     FROM {$wpdb->posts} AS p
                     INNER JOIN {$wpdb->postmeta} AS pm ON p.ID = pm.post_id
                     WHERE p.post_name = %s
                     AND p.post_status = 'payment-success'",
                    $giftCode
                );

                $existingPostID = $wpdb->get_var($query);

                return $existingPostID;
            }

            $existingPostID = isGiftCodeValid($giftCode);

            if ($existingPostID) {
                // Если запись существует, извлекаем значение duration из записи
                $duration = get_post_meta($existingPostID, 'booking_duration', true);
                $bookingtype = get_post_meta($existingPostID, 'booking_bookingtype', true);
                if (empty($duration)) {
                    // Если duration не установлен, можно добавить сообщение об ошибке или установить значение по умолчанию
                    $errors[] = [
                        'field'   => 'gift_code',
                        'message' => __('Значение duration не установлено для указанного кода подарункового сертификата.'),
                    ];
                }
                // Здесь можно выполнить другие действия, например, применить скидку
            } else {
                $errors[] = [
                    'field'   => 'gift_code',
                    'message' => __('Код подарункового сертифікату не існує.'),
                ];
            }
        }



        if ($is_fixed_date) {
            $available_dates = self::available_dates($type);

            $date = array_search_r(
                sanitize_text_field($request['date']),
                $available_dates
            );

            if ($date === null) {
                $errors[] = [
                    'field'   => 'duration',
                    'message' => __('Дата недоступна'),
                ];
            }

            if ($date) {
                $end = ($duration * 60) + $date;

                if ($type === 'Boeing') {

                    $existing = Order::where_and(
                        ['date', 'BETWEEN', [$date, $end - 1]],
                        [
                            'post_status' => ['payment-success', 'payment-pending'],
                            'meta_query'  => [
                                [
                                    'key'   => 'booking_bookingtype',
                                    'value' => 'boeing',
                                ],
                            ],
                        ]
                    );
                } else {
                    $existing = Order::where_and(
                        ['date', 'BETWEEN', [$date, $end - 1]],
                        [
                            'post_status' => ['payment-success', 'payment-pending'],
                            'meta_query'  => [
                                [
                                    'key'   => 'booking_bookingtype',
                                    'value' => 'f18',
                                ],
                            ],
                        ]
                    );
                }

                if ($existing) {
                    $errors[] = __('Цей діапазон часу недоступний');
                }
            }
        }

        $promocode = sanitize_text_field($request['promocode']);

        if ( ! empty($promocode)) {
            $discount = self::get_discount($promocode, $date);

            if ($discount['status'] === false) {
                $errors[] = [
                    'field'   => 'promocode',
                    'message' => $discount['message'],
                ];
            } else {
                $discount = ($discount['amount'] / 100) * $price;
                $price    = round($price - $discount, 2);
            }
        }

        if ( ! empty($errors)) {
            return new \WP_REST_Response($errors, 400);
        }

//        if ($has_gift) {
//            $date = get_post_meta($existingPostID, 'booking_date', true); // Получаем значение booking_date
//        }

        $order = new Order([
            'name'             => sanitize_text_field($request['name']),
            'phone'            => sanitize_text_field($request['phone']),
            'email'            => sanitize_email($request['email']),
            'date'             => $date,
            'duration'         => $duration,
            'price'            => $price,
            'comment'          => sanitize_text_field($request['comment']),
            'promocode'        => $promocode,
            'gift'             => $is_gift,
            'gift_code'        => $has_gift ? sanitize_text_field($request['gift_code']) : null,
            'delivery_address' => $delivery_address,
            'bookingtype'      => $bookingtype,
        ]);

        if ($order->price === floatval(0) || $has_gift) {
            $order->status = 'free-created';
            // $mailgun = new MailgunMessage();

            $saved = $order->save();

            $date  = date('d.m.Y', $order->date);
            $time  = date('H:i', $order->date);

            $message = "Шановний клієнте, дякуємо за  замовлення польоту на авіасимуляторі Боїнг 737!\n\nЧекаємо Вас {$date}/{$time} за адресою: вул. Герцена, 35, (внутрішній двір, окремий вхід між 4-ю та 5-ю секціями.)\n\nДля відкриття шлагбауму №1 телефонуйте за номером:\n+380 67 804 8487.\nШлагбаум №2:\n+380 67 804 8493.\n\nБажаємо гарного відпочинку та приємних вражень!\n\nПосилання на адресу в GoogleMaps:\nhttps://goo.gl/maps/o7TPzXDCD3vKaUeD7\n\nНа наш веб-сайт:\nhttps://aviasim.com.ua";

            if ($order->bookingtype == 'F18')
                $message = "Шановний клієнте, дякуємо за  замовлення польоту на авіасимуляторі F/A-18 Hornet!\n\nЧекаємо Вас {$date}/{$time} за адресою: вул. Герцена, 35, (внутрішній двір, окремий вхід між 4-ю та 5-ю секціями.)\n\nДля відкриття шлагбауму №1 телефонуйте за номером:\n+380 67 804 8487.\nШлагбаум №2:\n+380 67 804 8493.\n\nБажаємо гарного відпочинку та приємних вражень!\n\nПосилання на адресу в GoogleMaps:\nhttps://goo.gl/maps/o7TPzXDCD3vKaUeD7\n\nНа наш веб-сайт:\nhttps://aviasim.com.ua";

            $date = "Без дати";


            if($order->date){
                $date  = date('d.m.Y H:i', $order->date);
            }

            // $mailgun -> send_email_cert($order, "$date o $time");
            $message_admin = "Name: {$order->name}\nType: {$order->bookingtype}\nKey: {$order->key}\nEmail: {$order->email}\nPhone: {$order->phone}\nDate: {$date}\nDuration: {$order->duration} хв\nStatus: ✔️ {$order->status}\nGift code: {$order->gift_code}";


            $this->send_sms($order, $message, $message_admin);

            return new \WP_REST_Response([
                'message' => 'Redirection to order', 'redirect_url' => $saved->slug,
            ]);
        }

        $saved = $order->save();


        $liqpay = new LiqPay();

        $liqpay_server_url = get_field('liqpay_server_url', 'option');

        $params = [
            'version'     => '3',
            'action'      => 'pay',
            'amount'      => $saved->price,
            'currency'    => $this->currency['code'] ?? 'UAH',
            'description' => sprintf('%s %s %s %s', $saved->key, $saved->name, $saved->email, $saved->date),
            'order_id'    => $saved->ID.$saved->key,
            'result_url'  => $saved->slug,
            'server_url'  => rtrim($liqpay_server_url, '/').'/wp-json/avia/order/liqpay',
            'language'    => substr(get_bloginfo('language'), 0, 2),
        ];

        $link = $liqpay->get_link($params);

        $saved->liqpay_link = $link;
        $saved->save();

        $this->add_cron($saved->ID);

        return new \WP_REST_Response([
            'message' => 'Redirection to payment gateway', 'redirect_url' => $link,
        ]);
    }

    public function add_cron($id)
    {
        wp_schedule_single_event(strtotime('+15 minutes'), 'booking_check_order_pay_cron', [$id]);
    }

    public function check_order_pay($id)
    {
        $order = Order::get($id);

        if ( ! $order) {
            return;
        }

        if ($order->status === Order::$base_status) {
            $order->status = 'payment-failed';
            Log::info("Fail send sms 2");

            $date = "Без дати";


            if($order->date){
                $date  = date('d.m.Y H:i', $order->date);
            }



            $message_admin = "Name: {$order->name}\nKey: {$order->key}\nEmail: {$order->email}\nPhone: {$order->phone}\nDate: {$date}\nDuration: {$order->duration} хв\nStatus: ❌ {$order->status}";

            $message = "Вітаю!\nМи помітили, що Ви намагалися замовити політ на авіасимуляторі Боїнг 737 на нашому вебсайті, але оплата не була завершена. Часом це може траплятись через технічні збої.\n\nЯкщо у Вас виникли запитання або проблеми під час процесу оплати, будь ласка, дайте нам знати. Ми завжди готові допомогти та відповісти на Ваші запитання!\n\nЗверніться до нашого менеджера за номером 0507370800 для отримання додаткової інформації та допомоги.\n\nhttps://aviasim.com.ua";

            if ($order->bookingtype == 'F18')
                $message = "Вітаю!\nМи помітили, що Ви намагалися замовити політ на авіасимуляторі F/A-18 Hornet на нашому вебсайті, але оплата не була завершена. Часом це може траплятись через технічні збої.\n\nЯкщо у Вас виникли запитання або проблеми під час процесу оплати, будь ласка, дайте нам знати. Ми завжди готові допомогти та відповісти на Ваші запитання!\n\nЗверніться до нашого менеджера за номером 0507370800 для отримання додаткової інформації та допомоги.\n\nhttps://aviasim.com.ua";

            $this->send_sms($order, $message, $message_admin);



            $order->save();
        }
    }

    public static function available_dates($type = null): array
    {
        $time = current_time('timestamp');

        $today             = date('Ymd', $time);
        $today_ts          = strtotime($today);
        if ($type === 'Boeing') {
            $working_hours     = get_field('order_dates', 'option');
            $calendar_duration = get_field('order_calendar_duration', 'option') ?? 2;
            $calendar_duration = intval($calendar_duration) * 7;
        } else {
            $working_hours     = get_field('order_dates_new_2', 'option');
            $calendar_duration = get_field('order_calendar_duration_2', 'option') ?? 2;
            $calendar_duration = intval($calendar_duration) * 7;
        }

        $calendar_data = [];

        for ($i = 0; $i <= $calendar_duration; $i++) {
            $day_ts      = strtotime("+$i day", $today_ts);
            $day_of_week = date('w', $day_ts);

            $calendar_data[$day_ts] = array_map(function ($time) use ($day_ts) {
                $day = date('Ymd', $day_ts);

                return strtotime("$day $time");
            }, $working_hours[$day_of_week]);
        }

        $now_ts        = $time;
        $calendar_data = array_map(function ($day) use ($now_ts) {
            return array_filter($day, function ($ts) use ($now_ts) {
                return $ts > $now_ts;
            });
        }, $calendar_data);

        $current_url = $_SERVER['REQUEST_URI'];
        if ($type === 'F18' || strpos($current_url, '/order-2') !== false || strpos($current_url, '/f18') !== false) {
            $orders = Order::where_and(
                ['date', '>', $today_ts],
                [
                    'post_status' => ['free-created', 'payment-success', 'payment-pending'],
                    'meta_query'  => [
                        [
                            'key'   => 'booking_bookingtype',
                            'value' => 'f18',
                        ],
                    ],
                ]
            );

        } else {
            $orders = Order::where_and(
                ['date', '>', $today_ts],
                [
                    'post_status' => ['free-created', 'payment-success', 'payment-pending'],
                    'meta_query'  => [
                        [
                            'key'   => 'booking_bookingtype',
                            'value' => 'boeing',
                        ],
                    ],
                ]
            );
        }


        foreach ($orders as $order) {
            $day_ts = strtotime(date('Ymd', $order->date));

            if (isset($calendar_data[$day_ts]) && in_array($order->date, $calendar_data[$day_ts])) {
                $calendar_data[$day_ts] = array_filter(
                    $calendar_data[$day_ts],
                    function ($time) use ($order) {

                        return $time < $order->date || $time >= $order->end;
                    }
                );
            }
        }

        return $calendar_data;
    }

    public static function get_price($duration)
    {

        $durations = get_field('order_durations', 'option');

        $key = array_search($duration, array_column($durations, 'time'));

        if ($key !== false) {
            return $durations[$key]['price'];
        }

        return $durations[count($durations) - 1]['price'];
    }

    public static function get_price_2($duration)
    {

        $durations = get_field('order_durations_2', 'option');

        $key = array_search($duration, array_column($durations, 'time'));

        if ($key !== false) {
            return $durations[$key]['price'];
        }

        return $durations[count($durations) - 1]['price'];
    }

    public static function get_discount($promocode, $date = null): array
    {
        $code       = strtolower($promocode);
        $promocodes = get_field('order_promocodes', 'option');
        $key        = array_search($code, array_column($promocodes, 'code'));

        if ($key !== false) {
            $current_promocode = $promocodes[$key];

            $available = $current_promocode['available_to'];

            if ( ! empty($available)) {
                $available_ts = strtotime($available);
                $now_ts       = current_time('timestamp');

                if ($now_ts > $available_ts) {
                    return [
                        'status'  => false,
                        'message' => __('Термін дії промокоду закінчився'),
                    ];
                }
            }

            $restricted_days = $current_promocode['day_of_week'];

            if ( ! empty($restricted_days) && $date) {
                $current_day = date('w', $date);

                if (in_array($current_day, $restricted_days)) {
                    return [
                        'status'  => false,
                        'message' => __('Промокод на цей день не діє'),
                    ];
                }
            }

            return [
                'status'  => true,
                'amount'  => $current_promocode['discount'],
                'message' => '',
            ];
        }

        return [
            'status'  => false,
            'message' => __('Промокод не знайдено'),
        ];
    }
}
