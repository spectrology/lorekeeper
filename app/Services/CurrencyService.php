<?php namespace App\Services;

use App\Services\Service;

use DB;
use Config;

use App\Models\Currency\Currency;
use App\Models\User\UserCurrency;

class CurrencyService extends Service
{
    /*
    |--------------------------------------------------------------------------
    | Currency Service
    |--------------------------------------------------------------------------
    |
    | Handles the creation and editing of currency.
    |
    */

    /**
     * Creates a new currency.
     *
     * @param  array                  $data 
     * @param  \App\Models\User\User  $user
     * @return bool|\App\Models\Currency\Currency
     */
    public function createCurrency($data, $user)
    {
        DB::beginTransaction();

        try {
            // More specific validation
            if(!isset($data['is_user_owned']) && !isset($data['is_character_owned'])) throw new \Exception("Please choose if this currency is attached to users and/or characters.");

            $data = $this->populateData($data);

            $icon = $image = null;
            if(isset($data['icon']) && $data['icon']) {
                $data['has_icon'] = 1;
                $icon = $data['icon'];
                unset($data['icon']);
            }
            else $data['has_icon'] = 0;
            
            if(isset($data['image']) && $data['image']) {
                $data['has_image'] = 1;
                $image = $data['image'];
                unset($data['image']);
            }
            else $data['has_image'] = 0;

            $currency = Currency::create($data);

            if ($icon) $this->handleImage($icon, $currency->currencyIconPath, $currency->currencyIconFileName);
            if ($image) $this->handleImage($image, $currency->currencyImagePath, $currency->currencyImageFileName);

            return $this->commitReturn($currency);
        } catch(\Exception $e) { 
            $this->setError('error', $e->getMessage());
        }
        return $this->rollbackReturn(false);
    }

    /**
     * Updates a currency.
     *
     * @param  \App\Models\Currency\Currency  $currency
     * @param  array                          $data 
     * @param  \App\Models\User\User          $user
     * @return bool|\App\Models\Currency\Currency
     */
    public function updateCurrency($currency, $data, $user)
    {
        DB::beginTransaction();

        try {
            // More specific validation
            if(!isset($data['is_user_owned']) && !isset($data['is_character_owned'])) throw new \Exception("Please choose if this currency is attached to users and/or characters.");
            if(Currency::where('name', $data['name'])->where('id', '!=', $currency->id)->exists()) throw new \Exception("The name has already been taken.");
            if(Currency::where('abbreviation', $data['abbreviation'])->where('id', '!=', $currency->id)->exists()) throw new \Exception("The abbreviation has already been taken.");

            $data = $this->populateData($data, $currency);

            $icon = $image = null;
            if(isset($data['icon']) && $data['icon']) {
                $data['has_icon'] = 1;
                $icon = $data['icon'];
                unset($data['icon']);
            }
            
            if(isset($data['image']) && $data['image']) {
                $data['has_image'] = 1;
                $image = $data['image'];
                unset($data['image']);
            }

            $currency->update($data);

            if ($icon) $this->handleImage($icon, $currency->currencyIconPath, $currency->currencyIconFileName);
            if ($image) $this->handleImage($image, $currency->currencyImagePath, $currency->currencyImageFileName);

            return $this->commitReturn($currency);
        } catch(\Exception $e) { 
            $this->setError('error', $e->getMessage());
        }
        return $this->rollbackReturn(false);
    }

    /**
     * Processes user input for creating/updating a currency.
     *
     * @param  array                          $data 
     * @param  \App\Models\Currency\Currency  $currency
     * @return array
     */
    private function populateData($data, $currency = null)
    {
        if(isset($data['description']) && $data['description']) $data['parsed_description'] = parse($data['description']);

        if(!isset($data['is_user_owned'])) $data['is_user_owned'] = 0;
        if(!isset($data['is_character_owned'])) $data['is_character_owned'] = 0;
        
        if(!isset($data['is_displayed'])) $data['is_displayed'] = 0;

        if(!isset($data['allow_user_to_user'])) $data['allow_user_to_user'] = 0;
        if(!isset($data['allow_user_to_character'])) $data['allow_user_to_character'] = 0;
        if(!isset($data['allow_character_to_user'])) $data['allow_character_to_user'] = 0;
        
        $data['sort_user'] = $data['sort_character'] = 0;

        // Process the checkbox fields
        if(!($data['is_character_owned'] && $data['is_user_owned']))
        {
            $data['allow_user_to_character'] = $data['allow_character_to_user'] = 0;
        }
        if(!$data['is_user_owned']) 
        {
            $data['allow_user_to_user'] = $data['is_displayed'] = 0;
        }
        
        if(isset($data['remove_icon']) || isset($data['remove_image']))
        {
            if($currency) 
            {
                if($currency->has_icon && $data['remove_icon']) 
                { 
                    $data['has_icon'] = 0; 
                    $this->deleteImage($currency->currencyIconPath, $currency->currencyIconFileName); 
                }
                if($currency->has_image && $data['remove_image']) 
                { 
                    $data['has_image'] = 0; 
                    $this->deleteImage($currency->currencyImagePath, $currency->currencyImageFileName); 
                }
            }
            unset($data['remove_icon']);
            unset($data['remove_image']);
        }

        return $data;
    }
    
    /**
     * Deletes a currency.
     *
     * @param  \App\Models\Currency\Currency  $currency
     * @return bool
     */
    public function deleteCurrency($currency)
    {
        DB::beginTransaction();

        try {
            
            if(DB::table('loots')->where('rewardable_type', 'Currency')->where('rewardable_id', $currency->id)->exists()) throw new \Exception("A loot table currently distributes this currency as a potential reward. Please remove the currency before deleting it.");
            if(DB::table('prompt_rewards')->where('rewardable_type', 'Currency')->where('rewardable_id', $currency->id)->exists()) throw new \Exception("A prompt currently distributes this currency as a reward. Please remove the currency before deleting it.");
            if(DB::table('shop_stock')->where('currency_id', $currency->id)->exists()) throw new \Exception("A shop currently requires this currency to purchase an item. Please change the currency before deleting it.");

            // This will delete the currency in users' possession as well.
            // The reason this is allowed is that in instances where event currencies
            // are created for temporary use, it would be inconvenient to have to manually
            // remove them from user accounts before deleting the base currency.
            
            UserCurrency::where('currency_id', $currency->id)->delete();
            CharacterCurrency::where('currency_id', $currency->id)->delete();
            if($currency->has_image) $this->deleteImage($currency->currencyImagePath, $currency->currencyImageFileName); 
            if($currency->has_icon) $this->deleteImage($currency->currencyIconPath, $currency->currencyIconFileName); 
            $currency->delete();

            return $this->commitReturn(true);
        } catch(\Exception $e) { 
            $this->setError('error', $e->getMessage());
        }
        return $this->rollbackReturn(false);
    }
    
    /**
     * Sorts currency order.
     *
     * @param  array   $data
     * @param  string  $type
     * @return bool
     */
    public function sortCurrency($data, $type)
    {
        DB::beginTransaction();

        try {
            // explode the sort array and reverse it since the power order is inverted
            $sort = array_reverse(explode(',', $data));

            foreach($sort as $key => $s) {
                Currency::where('id', $s)->update([($type == 'user') ? 'sort_user' : 'sort_character' => $key]);
            }

            return $this->commitReturn(true);
        } catch(\Exception $e) { 
            $this->setError('error', $e->getMessage());
        }
        return $this->rollbackReturn(false);
    }
}