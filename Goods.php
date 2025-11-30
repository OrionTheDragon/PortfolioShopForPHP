<?php
    Class Goods {
        private String $SKU;
        private String $productName;
        private float $price;
        private int $quantity;

        public function __construct(String $SKU, String $productName, Float $price, int $quantity) {
            $this -> setSKU($SKU);
            $this -> setProductName($productName);
            $this -> setPrice($price);
            $this -> setQuantity($quantity);
        }

        public function getSKU(): String {
            return $this -> SKU;
        }
        public function setSKU(String $SKU) : void {
            $this -> SKU = $SKU;
        }
        public function getProductName(): String {
            return $this -> productName;
        }
        public function setProductName(String $productName) : void {
            $this -> productName = $productName;
        }
        public function getPrice(): Float {
            return $this -> price;
        }
        public function setPrice(Float $price) : void {
            $this -> price = $price;
        }
        public function getQuantity(): int {
            return $this -> quantity;
        }
        public function setQuantity(int $quantity) : void {
            $this -> quantity = $quantity;
        }
    }
?>