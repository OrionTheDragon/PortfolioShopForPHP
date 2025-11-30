<?php
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    require_once __DIR__ .'/Basket.php';
    class User {
        private $userid;
        private $username;
        private $password;
        private $userMoney;
        private Basket $basket;

        public function __construct($userID, $userName, $password, $userMoney) {
            $this -> setUserid($userID);
            $this -> setUsername($userName);
            $this -> setPassword($password);
            $this -> setUserMoney($userMoney);
        }
        
        public function getUserid() : int {
            return $this ->userid;
        }
        public function setUserid(int $userID) : void {
            $this ->userid = $userID;
        }
        public function getUsername() : string {
            return $this->username;
        }
        public function setUsername($username) : void {
            $this->username = $username;
        }
        public function getPassword() : string {
            return $this->password;
        }
        public function setPassword($password) : void {
            $this->password = $password;
        }
        public function getUserMoney() : float {
            return $this->userMoney;
        }
        public function setUserMoney($userMoney) : void {
            $this->userMoney = $userMoney;
        }
        public function getBasket() : Basket {
            return $this->basket;
        }
        public function setBasket(Basket $basket) : void {
            $this->basket = $basket;
        }

        // public function createBasket() : void {
        //     $this -> basket = new Basket($this -> getUserid());
        // }
    }
?>