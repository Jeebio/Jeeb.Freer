<?php
/*
  SOFTIRAN
  http://www.softiran.org

*/
	/*/-- اطلاعات کلی پلاگین*/
	$pluginData[jeebfreer][type] = 'payment';
	$pluginData[jeebfreer][name] = 'Jeeb Payment Gateway';
	$pluginData[jeebfreer][uniq] = 'jeebfreer';
	$pluginData[jeebfreer][description] = 'پلاگین پرداخت درگاه جیب';
	$pluginData[jeebfreer][author][name] = 'jeeb';
	$pluginData[jeebfreer][author][url] = 'https://jeeb.io/';
	$pluginData[jeebfreer][author][email] = '';
	
	/*/-- فیلدهای تنظیمات پلاگین*/
	$pluginData[jeebfreer][field][config][1][title] = 'apiKey';
	$pluginData[jeebfreer][field][config][1][name] = 'apiKey';	
	$pluginData[jeebfreer][field][config][2][title] = 'نوع شبکه live یا test';
	$pluginData[jeebfreer][field][config][2][name] = 'network';	
	$pluginData[jeebfreer][field][config][3][title] = 'زبان English یا Persian یا Auto-select';
	$pluginData[jeebfreer][field][config][3][name] = 'language';		
	$pluginData[jeebfreer][field][config][4][title] = 'Base-Currency :  IRR یا USD یا BTC یا EUR';
	$pluginData[jeebfreer][field][config][4][name] = 'baseCur';	
	$pluginData[jeebfreer][field][config][5][title] = 'Target-Currency : با , از هم جدا شود مثل btc,eth,xrp,xmr,bch,ltc,test-btc';
	$pluginData[jeebfreer][field][config][5][name] = 'targetcoin';	


	/*/-- تابع انتقال به دروازه پرداخت*/
	function gateway__jeebfreer($data)
	{
		global $smarty;
		$invoiceNumber =  trim($data[invoice_id]);
		$amount =  trim($data[amount]);

		
		switch ($data[language]) 
		{
		  case 'Auto-select':
			$lang=NULL;
			break;
		  case 'English':
			$lang="en";
			break;
		  case 'Persian':
			$lang="fa";
			break;
		}
				
		switch ($data[baseCur]) 
		{
		  case 'BTC':
			$baseCur="btc";
			break;
		  case 'IRR':
			$baseCur="irr";
			break;
		  case 'USD':
			$baseCur="usd";
			break;
		  case 'EUR':
			$baseCur="eur";
			break;
		}
		if($data[network] == 'test')
		{
			$params = array('BTC','XRP','XMR','LTC','BCH','ETH','TEST-BTC');	
		}else
		{
			$params = array('BTC','XRP','XMR','LTC','BCH','ETH');	
		}
		
	$data[targetcoin] = explode(',',$data[targetcoin]);
	
	foreach ($data[targetcoin] as $item) 
	{
		$item = strtoupper($item);
		in_array($item,$params) ? $target_cur .= strtolower($item ). "/" : $target_cur .="" ;
	}
	
	$baseUri      = "https://core.jeeb.io/api/" ;
	$signature = $data[apiKey];
	$callback = $data[callback].'&rands='.$invoiceNumber;
	$notification = $data[callback];
	$invoiceId = $invoiceNumber;

	$btc = convertIrrToBtc($baseUri, $amount, $signature, $baseCur);
	$orderNo = uniqid();


	$params = array(
	  'orderNo'          => $invoiceId,
	  'value'            => (float) $btc,
	  'webhookUrl'       => $notification,
	  'callBackUrl'      => $callback,
	  'allowReject'      => $data[network] == "test" ? false : true,
	  "coins"            => $target_cur,
	  "allowTestNet"     => $data[network] == "test" ? true  : false,
	  "language"         => $lang
	);

	$token = createInvoice($baseUri, $btc, $params, $signature);
	if($token['result']['token'])
	{
		redirectPayment($baseUri, $token['result']['token']);
	}else
	{
		//-- نمایش خطا
		$data[title] 	= 'خطای سیستم';
		$data[message] 	= '<font color="red">Error in create token : <br/>'.$token['errorMessage'].'</font><br/><br/><a href="index.php" class="button">بازگشت</a>';
		$smarty->assign('data', $data);
		$smarty->display('message.tpl');
		exit;
	}
	


	}
	
	/*/-- تابع بررسی وضعیت پرداخت*/
	function callback__jeebfreer($data)
	{
		global $db,$get,$post;

		if(isset($get[rands]))
		{
			$sql 		= "SELECT * FROM `payment` WHERE `payment_rand` = '".$get[rands]."' LIMIT 1;";
			$payment 	= $db->fetch($sql);
			if($payment[payment_status] == 3)
			{
				$ress = explode('|',$payment[payment_res_num]);
				$output[status]		= 1;
				$output[res_num]	= $ress[0];
				$output[ref_num]	= $ress[1];
				$output[payment_id]	= $payment[payment_id];
			}else
			{
				$output[status]	= 0;
				$output[message]= 'Payment was not successful.';
			}
		}else
		{
			$postdata = file_get_contents("php://input");
			$json = json_decode($postdata, true);

			if($json['signature']== $data[apiKey] && $json['orderNo'])
			{
				$RefNum = $json['referenceNo'];
				$sql 		= 'SELECT count(*) as cc FROM `payment` WHERE `payment_ref_num` = "'.$RefNum.'" LIMIT 1;';
				$payment_ref_num 	= $db->fetch($sql);
				if($payment_ref_num[cc] > 0 )
				{
					error_log("The receipt has already been used.");
					$output[status]	= 0;
					$output[message]= 'The receipt has already been used.';
					return $output;
				}
				if($json['stateId'] == '4')
				{
					$ResNum = $json['orderNo'];
					$sql 		= "SELECT * FROM `payment` WHERE `payment_rand` = '".$ResNum."' LIMIT 1;";
					$payment 	= $db->fetch($sql);
					if($payment[payment_status] == 1)
					{
						 $data = array(
						  "token" => $json["token"]
						);

						$data_string = json_encode($data);
						$api_key = $data[apiKey];
						$network_uri = "https://core.jeeb.io/api/" ;
						$url = $network_uri.'payments/'.$api_key.'/confirm';

						$ch = curl_init($url);
						curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
						curl_setopt($ch, CURLOPT_POSTFIELDS, $data_string);
						curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
						curl_setopt($ch, CURLOPT_HTTPHEADER, array(
							'Content-Type: application/json',
							'Content-Length: ' . strlen($data_string))
						);

						$result = curl_exec($ch);
						$data = json_decode( $result , true);
						error_log("data = ".var_export($data, TRUE));

						if($data['result']['isConfirmed'])
						{
							$update[payment_status] = 3;
							$update[payment_res_num] = $ResNum.'|'.$RefNum;
							$sql = $db->queryUpdate('payment', $update, 'WHERE `payment_id` = "' . $payment[payment_id] . '" LIMIT 1;');
							$db->execute($sql);
							return true;
						}
					
					}
				}
			}
		}
		return $output;
	}
	
	
	/*other function*/
	function convertIrrToBtc($url, $amount, $signature, $baseCur) 
	{
		$ch = curl_init($url.'currency?'.$signature.'&value='.$amount.'&base='.$baseCur.'&target=btc');
		curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "GET");
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_HTTPHEADER, array(
		'Content-Type: application/json')
		);
		$result = curl_exec($ch);
		$data = json_decode( $result , true);
		return (float) $data["result"];
	}

	function createInvoice($url, $amount, $options = array(), $signature) 
	{
		$post = json_encode($options);
		$ch = curl_init($url.'payments/' . $signature . '/issue/');
		curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
		curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_HTTPHEADER, array(
		'Content-Type: application/json',
		'Content-Length: ' . strlen($post))
		);
		$result = curl_exec($ch);
		$data = json_decode( $result , true);
		error_log("data = ".var_export($data, TRUE));
		error_log("data = ".var_export($options, TRUE));
		return $data;
	}

	function redirectPayment($url, $token) 
	{
		echo "<form id='form' method='post' action='".$url."payments/invoice'>".
		"<input type='hidden' autocomplete='off' name='token' value='".$token."'/>".
		"</form>".
		"<script type='text/javascript'>".
		"document.getElementById('form').submit();".
	"</script>";
	}