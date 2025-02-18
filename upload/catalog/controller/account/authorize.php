<?php
namespace Opencart\Catalog\Controller\Account;
/**
 * Class Authorize
 *
 * @package Opencart\Catalog\Controller\Account
 */
class Authorize extends \Opencart\System\Engine\Controller {
	/**
	 * Index
	 *
	 * @return void
	 */
	public function index(): void {
		$this->load->language('account/authorize');

		if (!$this->load->controller('account/login.validate')) {
			$this->response->redirect($this->url->link('account/login', 'language=' . $this->config->get('config_language'), true));
		}

		if (isset($this->request->cookie['authorize'])) {
			$token = $this->request->cookie['authorize'];
		} else {
			$token = '';
		}

		$this->document->setTitle($this->language->get('heading_title'));

		$this->load->model('account/customer');

		$login_info = $this->model_account_customer->getAuthorizeByToken($this->customer->getId(), $token);

		if (!$login_info) {
			// Create a token that can be stored as a cookie and will be used to identify device is safe.
			$token = oc_token(32);

			$authorize_data = [
				'token'      => $token,
				'ip'         => oc_get_ip(),
				'user_agent' => $this->request->server['HTTP_USER_AGENT']
			];

			$this->model_account_customer->addAuthorize($this->customer->getId(), $authorize_data);

			setcookie('authorize', $token, time() + 60 * 60 * 24 * 90);
		}

		$data['action'] = $this->url->link('account/authorize.save', 'customer_token=' . $this->session->data['customer_token']);

		// Set the code to be emailed
		$this->session->data['code'] = oc_token(4);

		if ($this->request->get['route'] != 'account/login' && $this->request->get['route'] != 'account/authorize') {
			$args = $this->request->get;

			$route = $args['route'];

			unset($args['route']);
			unset($args['customer_token']);

			$url = '';

			if ($args) {
				$url .= http_build_query($args);
			}

			$data['redirect'] = $this->url->link($route, $url, true);
		} else {
			$data['redirect'] = $this->url->link('account/account', 'customer_token=' . $this->session->data['customer_token'], true);
		}

		$data['customer_token'] = $this->session->data['customer_token'];

		$data['header'] = $this->load->controller('common/header');
		$data['footer'] = $this->load->controller('common/footer');

		$this->response->setOutput($this->load->view('account/authorize', $data));
	}

	/**
	 * Send
	 *
	 * @return void
	 */
	public function send(): void {
		$this->load->language('account/authorize');

		$json = [];

		$json['success'] = $this->language->get('text_resend');

		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($json));
	}

	/**
	 * Save
	 *
	 * @return void
	 */
	public function save(): void {
		$this->load->language('account/authorize');

		$json = [];

		if (!$this->load->controller('account/login.validate')) {
			$this->customer->logout();

			$json['redirect'] = $this->url->link('account/login', 'language=' . $this->config->get('config_language'), true);
		}

		if (isset($this->request->cookie['authorize'])) {
			$token = $this->request->cookie['authorize'];
		} else {
			$token = '';
		}

		if (!$json) {
			$this->load->model('account/customer');

			$authorize_info = $this->model_account_customer->getAuthorizeByToken($this->customer->getId(), $token);

			if ($authorize_info) {
				if (($authorize_info['attempts'] <= 2) && (!isset($this->request->post['code']) || !isset($this->session->data['code']) || ($this->request->post['code'] != $this->session->data['code']))) {
					$json['error'] = $this->language->get('error_code');

					$this->model_account_customer->editAuthorizeTotal($authorize_info['customer_authorize_id'], $authorize_info['total'] + 1);
				}

				if ($authorize_info['attempts'] >= 2) {
					$json['redirect'] = $this->url->link('account/authorize.unlock', 'customer_token=' . $this->session->data['customer_token'], true);
				}
			} else {
				$json['error'] = $this->language->get('error_code');
			}
		}

		if (!$json) {
			$this->model_account_customer->editAuthorizeStatus($authorize_info['customer_authorize_id'], true);
			$this->model_account_customer->editAuthorizeTotal($authorize_info['customer_authorize_id'], 0);

			if (isset($this->request->post['redirect'])) {
				$redirect = urldecode(html_entity_decode($this->request->post['redirect'], ENT_QUOTES, 'UTF-8'));
			} else {
				$redirect = '';
			}

			// Register the cookie for security.
			if ($redirect && str_starts_with($redirect, HTTP_SERVER)) {
				$json['redirect'] = $redirect . '&customer_token=' . $this->session->data['customer_token'];
			} else {
				$json['redirect'] = $this->url->link('account/account', 'customer_token=' . $this->session->data['customer_token'], true);
			}
		}

		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($json));
	}

	/**
	 * Unlock
	 *
	 * @return void
	 */
	public function unlock(): void {
		$this->load->language('account/authorize');

		if (isset($this->request->cookie['authorize'])) {
			$token = $this->request->cookie['authorize'];
		} else {
			$token = '';
		}

		$this->load->model('account/customer');

		$authorize_info = $this->model_account_customer->getAuthorizeByToken($this->customer->getId(), $token);

		if ($authorize_info && $authorize_info['status']) {
			// Redirect if already have a valid token.
			$this->response->redirect($this->url->link('account/account', 'customer_token=' . $this->session->data['customer_token'], true));
		}

		$data['customer_token'] = $this->session->data['customer_token'];

		$data['header'] = $this->load->controller('common/header');
		$data['footer'] = $this->load->controller('common/footer');

		$this->response->setOutput($this->load->view('account/authorize_unlock', $data));
	}

	/**
	 * Confirm
	 *
	 * @return void
	 */
	public function confirm(): void {
		$this->load->language('account/authorize');

		$json = [];

		$json['success'] = $this->language->get('text_link');

		// Create reset code
		$this->load->model('account/customer');

		$this->model_account_customer->editCode($this->customer->getEmail(), oc_token(32));

		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($json));
	}

	/**
	 * Reset
	 *
	 * @return void
	 */
	public function reset(): void {
		$this->load->language('account/authorize');

		if (isset($this->request->get['email'])) {
			$email = (string)$this->request->get['email'];
		} else {
			$email = '';
		}

		if (isset($this->request->get['code'])) {
			$code = (string)$this->request->get['code'];
		} else {
			$code = '';
		}

		$this->load->model('account/customer');

		$customer_info = $this->model_account_customer->getCustomerByEmail($email);

		if ($customer_info && $customer_info['code'] && $code && $customer_info['code'] === $code) {
			$this->model_account_customer->resetAuthorizes($customer_info['customer_id']);

			$this->model_account_customer->editCode($email, '');

			$this->session->data['success'] = $this->language->get('text_unlocked');

			$this->response->redirect($this->url->link('account/authorize', 'customer_token=' . $this->session->data['customer_token'], true));
		} else {
			$this->customer->logout();

			$this->model_account_customer->editCode($email, '');

			$this->session->data['error'] = $this->language->get('error_reset');

			$this->response->redirect($this->url->link('account/login', '', true));
		}
	}
}
