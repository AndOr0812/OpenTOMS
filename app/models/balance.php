<?php

class Balance extends AppModel {
    var $name = 'Balance';
	var $belongsTo ='Account, Fund, Currency, Sec';
	
	//calculate the month end balances, using the last month end balances and this month's general ledger
	function monthend($fund, $month, $year) {
		//first get the end of last month's balances
		$lm = $month - 1;
		$ly = $year;
		if ($lm == 0) {
			$lm = 12;
			$ly = $ly - 1;
		}
		
		//get last month end balances
		$baldata = $this->find('all', array('conditions'=>array('Balance.act =' => 1, 'Balance.ledger_month =' => $lm, 'Balance.ledger_year =' => $ly, 'Balance.fund_id =' => $fund)));
				
		//get this month's ledger entries
		App::import('model','Ledger');
		$ledger = new Ledger();
		$ledgdata = $ledger->find('all', array('conditions'=>array('Ledger.act =' => 1, 'Ledger.ledger_month =' => $month, 'Ledger.ledger_year =' => $year, 'Ledger.fund_id =' => $fund)));
		
		//Aggregate these two sets together, GROUP BY (account_id, sec_id)
		$newbal = array();
		foreach ($baldata as $b) {
			$newbal[$b['Balance']['account_id']][$b['Balance']['sec_id']][] = array('ledger_debit'=>$b['Balance']['balance_debit'],
																					'ledger_credit'=>$b['Balance']['balance_credit'],
																					'quantity'=>$b['Balance']['balance_quantity'],
																					'currency_id'=>$b['Balance']['currency_id']);
		}
				
		foreach ($ledgdata as $l) {
			$newbal[$l['Ledger']['account_id']][$l['Ledger']['sec_id']][] = array(  'ledger_debit'=>$l['Ledger']['ledger_debit'],
																					'ledger_credit'=>$l['Ledger']['ledger_credit'],
																					'quantity'=>$l['Ledger']['ledger_quantity'],
																					'currency_id'=>$l['Ledger']['currency_id']);
		}
		
		//deactivate all previous balances for this month end
		$result = $this->updateAll( array('Balance.act' => 0), 
										array(	'Balance.ledger_month =' => $month, 
												'Balance.ledger_year =' => $year, 
												'Balance.fund_id =' => $fund,
												'Balance.locked =' => 0,
												'Balance.act =' => 1));
		
		if (!$result) { return false; }
		//we have a two-dimensional array of aggregated data, save it to the table now
		foreach ($newbal as $acc=>$n1) {
			foreach ($n1 as $sec=>$n2) {
				$totdeb = 0;
				$totcred = 0;
				$totqty = 0;
				$ccy = 0;
				foreach ($n2 as $d) {
					$totdeb += $d['ledger_debit'];
					$totcred += $d['ledger_credit'];
					$totqty += $d['quantity'];
					$ccy = $d['currency_id'];
				}
				$data['Balance'] = array('act' => 1,
										 'locked' => 0,
										 'crd'=>DboSource::expression('NOW()'),
										 'fund_id' => $fund,
										 'account_id'=>$acc,
										 'ledger_month'=>$month,
										 'ledger_year'=>$year,
										 'balance_debit'=>$totdeb,
										 'balance_credit'=>$totcred,
										 'currency_id'=>$ccy,
										 'balance_quantity'=>$totqty,
										 'sec_id'=>$sec);
				$this->create($data);
				$this->save();
			}
		}
		
		return true;
	}
	
	
	//put prices and fx rates next to balance items by left joining onto the prices table
	//$fund is id of fund, $pdate is UNIX time
	function attachprices($fund, $pdate) {
		$params=array(	'fields' => array(	'Fund.fund_name',
											'Account.id',
											'Account.account_name',
											'Balance.balance_debit',
											'Balance.balance_credit',
											'Currency.currency_iso_code',
											'Sec.sec_name',
											'Balance.balance_quantity',
											'Price.price',
											'Price.fx_rate',
											'Price.sec_id',
											'Balance.sec_id'
														),
						'joins' => array(
										array('table'=>'prices',
											  'alias'=>'Price',
											  'type'=>'left',
											  'foreignKey'=>false,
											  'conditions'=>
													array(	'Price.sec_id=Balance.sec_id',
															"Price.price_date='".date('Y-m-d', $pdate)."'")
											  )
										),
						'conditions' => array('Balance.act ='=>1, 'Balance.fund_id ='=>$fund, 'Balance.ledger_month ='=>date('n', $pdate), 'Balance.ledger_year ='=>date('Y', $pdate)),
						'order' => array('Balance.account_id')
					);
						
		return ($this->find('all', $params));
	}
	
	
	//lock month end
	function lock($fund, $month, $year) {
		$result = $this->updateAll( array('Balance.locked' => 1), 
										array(	'Balance.ledger_month =' => $month, 
												'Balance.ledger_year =' => $year, 
												'Balance.fund_id =' => $fund,
												'Balance.act =' => 1));
		return ($result);
	}
	
	//unlock month end
	function unlock($fund, $month, $year) {
		$result = $this->updateAll( array('Balance.locked' => 0), 
										array(	'Balance.ledger_month =' => $month, 
												'Balance.ledger_year =' => $year, 
												'Balance.fund_id =' => $fund,
												'Balance.act =' => 1));
		//unlock all future month ends
		$result2 = $this->updateAll( array('Balance.locked' => 0), 
										array(	'Balance.ledger_year >' => $year, 
												'Balance.fund_id =' => $fund,
												'Balance.act =' => 1));
		$result3 = $this->updateAll( array('Balance.locked' => 0), 
										array(	'AND' => array( 'Balance.ledger_year =' => $year,
																'Balance.ledger_month >'=> $month),
												'Balance.fund_id =' => $fund,
												'Balance.act =' => 1));
		return ($result && $result2 && $result3);
	}
	
	//is this month locked?
	//the better way would be to have a record dates table with a locked status field, maybe do this for a future version.
	function islocked($fund, $month, $year) {
		$result = $this->find('first', array('conditions'=>array('Balance.fund_id ='=>$fund, 'Balance.ledger_month ='=>$month, 'Balance.ledger_year ='=>$year, 'Balance.act ='=>1), 'fields'=>array('Balance.locked')));
		
		if (empty($result['Balance']['locked'])) {
			return false;
		}
		else if ($result['Balance']['locked'] == 1) {
			return true;
		}
		else {
			return false;
		}
	}
	
	
	//clear all the balance data for this fund
	//!Warning, use with extreme caution!
	function wipe($fund) {
		$result = $this->updateAll( array('Balance.locked' => 0,
										  'Balance.act' => 0), 
										array(	'Balance.fund_id =' => $fund));
		return $result;
	}
}

?>