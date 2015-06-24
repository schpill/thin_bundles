 <?php
    /**
     * Thin is a swift Framework for PHP 5.4+
     *
     * @package    Thin
     * @version    1.0
     * @author     Gerald Plusquellec
     * @license    BSD License
     * @copyright  1996 - 2015 Gerald Plusquellec
     * @link       http://github.com/schpill/thin
     */

    namespace Cartify;

    /**
     * Libraries we can use.
     */
    use Thin\Config;
    use Thin\Exception;
    use Thin\Arrays;

    /**
     * ShopCart class.
     */
    class ShopCart
    {
        /**
         * Regular expression to validate item ID's.
         *
         *  Allowed:
         *      alpha-numeric
         *      dashes
         *      underscores
         *      periods
         *
         * @access   protected
         * @var      string
         */
        protected $itemIdRules = '\.a-z0-9_-';

        /**
         * Holds the cart name.
         *
         * @access   protected
         * @var      string
         */
        protected $cartName = null;

        /**
         * Shopping Cart contents.
         *
         * @access   protected
         * @var      array
         */
        protected $cartContents = null;

        /**
         * Shopping cart initializer.
         *
         * @access   public
         * @return   void
         */
        public function __construct($cartName = null)
        {
            // Store the cart name.
            //
            $this->cartName = is_null($cartName) ? Config::get('application.cart.name', 'cart') : $cartName;

            // Grab the shopping cart array from the session.
            //
            arraySet($this->cartContents, $this->cartName, session('cart_' . $this->cartName)->getContent());

            // We don't have any cart session, set some base values.
            //
            if (is_null(arrayGet($this->cartContents, $this->cartName, null))) {
                arraySet($this->cartContents, $this->cartName, array('cart_total' => 0, 'total_items' => 0));
            }
        }

        /**
         * Returns information about an item.
         *
         * @access   public
         * @param    string
         * @return   array
         * @throws   Exception
         */
        public function item($rowid = null)
        {
            // Check if we have a valid rowid.
            //
            if (is_null($rowid)) {
                throw new Exception("No valid rowId");
            }

            // Check if this item exists.
            //
            if (!$item = arrayGet($this->cartContents, $this->cartName . '.' . $rowid)) {
                throw new Exception("This item does not exist.");
            }

            // Return all the item information.
            //
            return $item;
        }

        /**
         * Inserts items into the cart.
         *
         * @access   public
         * @param    array
         * @return   mixed
         * @throws   Exception
         */
        public function insert($items = array())
        {
            // Check if we have data.
            //
            if (!Arrays::is($items) || count($items) === 0) {
                throw new Exception("No data provided.");
            }

            // We only update the cart when we insert data into it.
            //
            $updateCart = false;

            // Single item?
            //
            if (!isset($items[0])) {
                // Check if the item was added to the cart.
                //
                if ($rowid = $this->_insert($items)) {
                    $updateCart = true;
                }
            }

            // Multiple items.
            //
            else {
                // Loop through the items.
                //
                foreach ($items as $item) {
                    // Check if the item was added to the cart.
                    //
                    if ($this->_insert($item)) {
                        $updateCart = true;
                    }
                }
            }

            // Update the cart if the insert was successful.
            //
            if ($updateCart === true) {
                // Update the cart.
                //
                $this->updateCart();

                // See what we want to return.
                //
                return (isset($rowid) ? $rowid : true);
            }

            // Something went wrong.
            //
            throw new Exception('Something went wrong');
        }

        /**
         * Updates an item quantity, or items quantities.
         *
         * @access   public
         * @param    array
         * @return   boolean
         * @throws   Exception
         */
        public function update($items = array())
        {
            // Check if we have data.
            //
            if (!Arrays::is($items) or count($items) === 0) {
                throw new Exception('No data provided.');
            }

            // We only update the cart when we insert data into it.
            //
            $updateCart = false;

            // Single item.
            //
            if ( ! isset($items[0])) {
                // Check if the item was updated.
                //
                if ($this->_update($items) === true) {
                    $updateCart = true;
                }
            }

            // Multiple items.
            //
            else {
                // Loop through the items.
                //
                foreach ($items as $item) {
                    // Check if the item was updated.
                    //
                    if ($this->_update($item) === true) {
                        $updateCart = true;
                    }
                }
            }

            // Update the cart if the insert was successful.
            //
            if ($updateCart === true) {
                // Update the cart.
                //
                $this->updateCart();

                // We are done here.
                //
                return true;
            }

            // Something went wrong.
            //
            throw new Exception('Something went wrong.');
        }

        /**
         * Removes an item from the cart.
         *
         * @access   public
         * @param    integer
         * @return   boolean
         * @throws   Exception
         */
        public function remove($rowid = null)
        {
            // Check if we have an id passed.
            //
            if (is_null($rowid)) {
                throw new Exception("No valid rowid provided.");
            }

            // Try to remove the item.
            //
            if ($this->update(array('rowid' => $rowid, 'qty' => 0))) {
                // Success, item removed.
                //
                return true;
            }

            // Something went wrong.
            //
            throw new Exception('Something went wrong.');
        }

        /**
         * Returns the cart total.
         *
         * @access   public
         * @return   integer
         */
        public function total()
        {
            return arrayGet($this->cartContents, $this->cartName . '.cart_total', 0);
        }

        /**
         * Returns the total item count.
         *
         * @access   public
         * @return   integer
         */
        public function total_items()
        {
            return arrayGet($this->cartContents, $this->cartName . '.total_items', 0);
        }

        /**
         * Returns the cart contents.
         *
         * @access   public
         * @return   array
         */
        public function contents()
        {
            // Get the cart contents.
            //
            $cart = arrayGet($this->cartContents, $this->cartName);

            // Remove these so they don't create a problem when showing the cart table.
            //
            arrayUnset($cart, 'total_items');
            arrayUnset($cart, 'cart_total');

            // Return the cart contents.
            //
            return $cart;
        }

        /**
         * Checks if an item has options.
         *
         * It returns 'true' if the rowid passed to this function correlates to an item
         * that has options associated with it, otherwise returns 'false'.
         *
         * @access   public
         * @param    integer
         * @return   boolean
         */
        public function has_options($rowid = null)
        {
            // Check if this item have options.
            //
            return (arrayGet($this->cartContents, $this->cartName . '.' . $rowid . '.options') ? true : false);
        }

        /**
         * Returns an array of options, for a particular item row ID.
         *
         * @access   public
         * @param    integer
         * @return   array
         */
        public function item_options($rowid = null)
        {
            // Return this item options.
            //
            return arrayGet($this->cartContents, $this->cartName . '.' . $rowid . '.options', array());
        }

        /**
         * Insert an item into the cart.
         *
         * @access   protected
         * @param    array
         * @return   string
         * @throws   Exception
         */
        protected function _insert($item = array())
        {
            // Check if we have data.
            //
            if (!Arrays::is($item) || count($item) == 0) {
                throw new Exception("No data provided.");
            }

            // Required indexes.
            //
            $required_indexes = array('id', 'qty', 'price', 'name');

            // Loop through the required indexes.
            //
            foreach ($required_indexes as $index) {
                // Make sure the array contains this index.
                //
                if (!isset($item[$index])) {
                    throw new Exception('Required index [' . $index . '] is missing.');
                }
            }

            // Make sure the quantity is a number and remove any leading zeros.
            //
            $item['qty'] = (float) $item['qty'];

            // If the quantity is zero or blank there's nothing for us to do.
            //
            if (!is_numeric($item['qty']) || $item['qty'] == 0) {
                throw new Exception("No quantity provided.");
            }

            // Validate the item id.
            //
            if (!preg_match('/^[' . $this->itemIdRules . ']+$/i', $item['id'])) {
                throw new Exception("Wrong id pattern.");
            }

            // Prepare the price.
            // Remove leading zeros and anything that isn't a number or decimal point.
            //
            $item['price'] = (float) $item['price'];

            // Is the price a valid number?
            //
            if (!is_numeric($item['price'])) {
                throw new Exception("Wrong price format.");
            }

            // Create a unique identifier.
            //
            if (isset($item['options']) and count($item['options']) > 0) {
                $rowid = md5($item['id'] . implode('', $item['options']));
            } else {
                $rowid = md5($item['id']);
            }

            // Make sure we have the correct data, and incremente the quantity.
            //
            $item['rowid'] = $rowid;
            $item['qty']  += (int) arrayGet($this->cartContents, $this->cartName . '.' . $rowid . '.qty', 0);

            // Store the item in the shopping cart.
            //
            arraySet($this->cartContents, $this->cartName . '.' . $rowid, $item);

            // Item added with success.
            //
            return $rowid;
        }

        /**
         * Updates the cart items.
         *
         * @access   protected
         * @param    array
         * @return   boolean
         * @throws   Exception
         */
        protected function _update($item = array())
        {
            // Item row id.
            //
            $rowid = arrayGet($item, 'rowid', null);

            // Make sure the row id is passed.
            //
            if (is_null($rowid)) {
                throw new Exception("No rowid provided.");
            }

            // Check if the item exists in the cart.
            //
            if (is_null(arrayGet($this->cartContents, $this->cartName . '.' . $rowid, null))) {
                throw new Exception("This item does not exist.");
            }

            // Prepare the quantity.
            //
            $qty = (float) arrayGet($item, 'qty');

            // Unset the qty and the rowid.
            //
            arrayUnset($item, 'rowid');
            arrayUnset($item, 'qty');

            // Is the quantity a number ?
            //
            if (!is_numeric($qty)) {
                throw new Exception("Wrong quantity format.");
            }

            // Check if we have more data, like options or custom data.
            //
            if ( ! empty($item)) {
                #$item['rowid'] = $rowid;
                #$this->cartContents[ $this->cartName ][ $rowid ] = $item;
                // Loop through the item data.
                //
                foreach ($item as $key => $val) {
                    // Update the item data.
                    //
                    arraySet($this->cartContents, $this->cartName . '.' . $rowid . '.' . $key, $val);
                }
            }

            // If the new quantaty is the same the already in the cart, there is nothing more to update.
            //
            if (arrayGet($this->cartContents, $this->cartName . '.' . $rowid . '.qty') == $qty) {
                return true;
            }

            // If the quantity is zero or less, we will be removing the item from the cart.
            //
            if ($qty <= 0) {
                // Remove the item from the cart.
                //
                arrayUnset($this->cartContents, $this->cartName . '.' . $rowid);
            }

            // Quantity is greater than zero, let's update the item cart.
            //
            else {
                // Update the item quantity.
                //
                arraySet($this->cartContents, $this->cartName . '.' . $rowid . '.qty', $qty);
            }

            // Cart updated with success.
            //
            return true;
        }

        /**
         * Updates the cart session.
         *
         * @access   protected
         * @return   boolean
         */
        protected function updateCart()
        {
            //
            //
            arraySet($this->cartContents, $this->cartName . '.total_items', 0);
            arraySet($this->cartContents, $this->cartName . '.cart_total', 0);

            // Loop through the cart items.
            //
            foreach (arrayGet($this->cartContents, $this->cartName) as $rowid => $item) {
                // Make sure the array contains the proper indexes.
                //
                if (!Arrays::is($item) || !isset($item['price']) || !isset($item['qty'])) {
                    continue;
                }

                // Calculations...
                //
                $this->cartContents[ $this->cartName ]['cart_total'] += ($item['price'] * $item['qty']);
                $this->cartContents[ $this->cartName ]['total_items'] += $item['qty'];

                // Calculate the item subtotal.
                //
                $subtotal = (arrayGet($this->cartContents, $this->cartName . '.' . $rowid . '.price') * arrayGet($this->cartContents, $this->cartName . '.' . $rowid . '.qty'));

                // Set the subtotal of this item.
                //
                arraySet($this->cartContents, $this->cartName . '.' . $rowid . '.subtotal', $subtotal);
            }

            // Is our cart empty?
            //
            if (count(arrayGet($this->cartContents, $this->cartName)) <= 2) {
                // If so we delete it from the session.
                //
                $this->destroy();

                // Nothing more to do here...
                //
                return false;
            }

            // Update the cart session data.
            //
            session('cart_' . $this->cartName)->setContent(arrayGet($this->cartContents, $this->cartName));
            // Success.
            //
            return true;
        }

        /**
         * Empties the cart, and removes the session.
         *
         * @access   public
         * @return   void
         */
        public function destroy()
        {
            // Remove all the data from the cart and set some base values
            //
            arraySet($this->cartContents, $this->cartName, array('cart_total' => 0, 'total_items' => 0));

            // Remove the session.
            //
            session('cart_' . $this->cartName)->erase();
        }
    }
