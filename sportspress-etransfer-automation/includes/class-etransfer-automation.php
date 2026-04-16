<?php
/**
 * e-Transfer Automation Core Class
 *
 * @author Cody (lusky3)
 */

if (!defined('ABSPATH')) {
    exit;
}

class SPET_ETransfer_Automation
{

    public function __construct()
    {
        add_action('rest_api_init', array($this, 'register_webhook_endpoint'));
    }

    public function register_webhook_endpoint()
    {
        register_rest_route('spet/v1', '/etransfer-webhook', array(
            'methods' => 'POST',
            'callback' => array($this, 'handle_webhook'),
            'permission_callback' => '__return_true'
        ));
    }

    public function handle_webhook($request)
    {
        $body = $request->get_body();
        $headers = $request->get_headers();

        // Verify signature
        if (!$this->verify_signature($body, $headers)) {
            return new WP_Error('invalid_signature', 'Invalid webhook signature', array('status' => 401));
        }

        // Check WooCommerce is available
        if (!function_exists('wc_get_orders')) {
            return new WP_Error('woocommerce_missing', 'WooCommerce is not active', array('status' => 503));
        }

        $data = json_decode($body, true);
        if (!$data) {
            return new WP_Error('invalid_json', 'Invalid JSON payload', array('status' => 400));
        }

        // Extract payment data
        $payment_data = $this->extract_payment_data($data);
        if (!$payment_data) {
            return new WP_Error('invalid_payment_data', 'Could not extract payment data', array('status' => 400));
        }

        // Check for duplicate reference number
        if (SPET_Database::reference_number_exists($payment_data['reference_number'])) {
            SPET_Database::log_etransfer_activity(array(
                'from_email' => $payment_data['customer_email'],
                'from_name' => $payment_data['sender_name'],
                'amount' => $payment_data['amount'],
                'reference_number' => $payment_data['reference_number'],
                'match_criteria' => '',
                'order_id' => null,
                'result' => 'Duplicate webhook - reference number already processed',
                'webhook_data' => $data,
                'payment_data' => $payment_data
            ));
            return rest_ensure_response(array('status' => 'duplicate', 'message' => 'Reference number already processed'));
        }

        // Find matching order
        $order_id = $this->find_matching_order($payment_data);

        // Validate amount if order was matched
        $amount_mismatch = false;
        if ($order_id) {
            $order = wc_get_order($order_id);
            if ($order) {
                $order_total = floatval($order->get_total());
                $payment_amount = floatval($payment_data['amount']);
                if (abs($order_total - $payment_amount) > 0.01) {
                    $amount_mismatch = true;
                    $payment_data['match_criteria'] = ($payment_data['match_criteria'] ?? '') .
                        sprintf(' | Amount mismatch: paid $%.2f, order $%.2f', $payment_amount, $order_total);
                }
            }
        }

        // Determine result message
        if (!$order_id) {
            $result = 'No matching order found';
        } elseif ($amount_mismatch) {
            $result = sprintf('Amount mismatch - paid $%.2f, order $%.2f - pending manual review',
                $payment_data['amount'], floatval(wc_get_order($order_id)->get_total()));
        } else {
            $result = 'Order updated successfully';
        }

        // Log activity
        SPET_Database::log_etransfer_activity(array(
            'from_email' => $payment_data['customer_email'],
            'from_name' => $payment_data['sender_name'],
            'amount' => $payment_data['amount'],
            'reference_number' => $payment_data['reference_number'],
            'match_criteria' => $payment_data['match_criteria'] ?? '',
            'order_id' => $amount_mismatch ? null : $order_id,
            'result' => $result,
            'webhook_data' => $data,
            'payment_data' => $payment_data
        ));

        if ($order_id && !$amount_mismatch) {
            $this->process_payment($order_id, $payment_data);
            return rest_ensure_response(array('status' => 'success', 'order_id' => $order_id));
        }

        if ($amount_mismatch) {
            return rest_ensure_response(array('status' => 'amount_mismatch', 'message' => $result, 'order_id' => $order_id));
        }

        return rest_ensure_response(array('status' => 'no_match', 'message' => 'No matching order found'));
    }

    private function verify_signature($body, $headers)
    {
        $signature = '';
        if (isset($headers['x_signature'][0])) {
            $signature = $headers['x_signature'][0];
        }
        elseif (isset($headers['x-signature'][0])) {
            $signature = $headers['x-signature'][0];
        }

        if (empty($signature)) {
            return false;
        }

        $secret = get_option('spet_webhook_secret', '');
        if (empty($secret)) {
            return false;
        }

        $expected = hash_hmac('sha256', $body, $secret);
        return hash_equals($expected, $signature);
    }

    private function extract_payment_data($data)
    {
        $text = isset($data['text']) ? $data['text'] : '';
        if (empty($text)) {
            return false;
        }

        // Extract reference number
        if (preg_match('/Reference Number:\s*\n\s*([A-Z\d]+)/i', $text, $matches)) {
            $reference_number = $matches[1];
        }
        else {
            return false;
        }

        // Extract amount
        if (preg_match('/Amount:\s*\n\s*\$([\d,]+\.?\d*)/', $text, $matches)) {
            $amount = floatval(str_replace(',', '', $matches[1]));
        }
        else {
            return false;
        }

        // Extract sender name
        if (preg_match('/Sent From:\s*\n\s*(.+)/i', $text, $matches)) {
            $sender_name = trim($matches[1]);
        }
        else {
            $sender_name = '';
        }

        // Extract customer email from Reply-To
        $customer_email = '';
        if (isset($data['reply_to'])) {
            if (is_array($data['reply_to']) && isset($data['reply_to']['address'])) {
                $customer_email = $data['reply_to']['address'];
            }
            else {
                $customer_email = $data['reply_to'];
            }
        }

        return array(
            'reference_number' => $reference_number,
            'amount' => $amount,
            'sender_name' => $sender_name,
            'customer_email' => $customer_email
        );
    }

    private function find_matching_order(&$payment_data)
    {
        // Strategy 1: Email match
        if (!empty($payment_data['customer_email'])) {
            $orders = wc_get_orders(array(
                'billing_email' => $payment_data['customer_email'],
                'status' => 'on-hold',
                'limit' => 1,
                'orderby' => 'date',
                'order' => 'DESC'
            ));

            if (!empty($orders)) {
                $payment_data['match_criteria'] = 'Reply-To Email (' . $payment_data['customer_email'] . ')';
                return $orders[0]->get_id();
            }
        }

        // Strategy 2: Name match (exact or similar names)
        if (!empty($payment_data['sender_name'])) {
            $orders = wc_get_orders(array(
                'status' => 'on-hold',
                'limit' => 50,
                'orderby' => 'date',
                'order' => 'DESC'
            ));

            foreach ($orders as $order) {
                $billing_name = $order->get_billing_first_name() . ' ' . $order->get_billing_last_name();
                if (SPET_Name_Matcher::names_match($billing_name, $payment_data['sender_name'])) {
                    $payment_data['match_criteria'] = 'Customer Name (' . $payment_data['sender_name'] . ')';
                    return $order->get_id();
                }
            }
        }

        return null;
    }

    private function process_payment($order_id, $payment_data)
    {
        $order = wc_get_order($order_id);
        if (!$order) {
            return false;
        }

        // Set transaction ID
        if (!empty($payment_data['reference_number'])) {
            $order->set_transaction_id($payment_data['reference_number']);
        }

        // Add order note
        $order->add_order_note('e-Transfer payment received and processed automatically.');

        // Update status
        $order->update_status('completed', 'Payment confirmed via e-Transfer automation.');

        $order->save();

        return true;
    }
}
