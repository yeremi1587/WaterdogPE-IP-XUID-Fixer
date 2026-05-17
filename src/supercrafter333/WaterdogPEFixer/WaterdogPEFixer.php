<?php

namespace supercrafter333\WaterdogPEFixer;

use pocketmine\event\Listener;
use pocketmine\event\server\DataPacketReceiveEvent;
use pocketmine\network\mcpe\JwtUtils;
use pocketmine\network\mcpe\protocol\LoginPacket;
use pocketmine\network\raklib\RakLibInterface;
use pocketmine\plugin\PluginBase;
use ReflectionClass;
use ReflectionException;
use ReflectionProperty;

class WaterdogPEFixer extends PluginBase implements Listener
{

    public function onEnable(): void
    {
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
    }

    #################################
    ##[Fix Waterdog(PE) IP & XUID ]##
    #################################
    public function onPacketReceive(DataPacketReceiveEvent $event): void {
        $packet = $event->getPacket();
        if($packet instanceof LoginPacket) {
            foreach ( $this->getServer()->getNetwork()->getInterfaces() as $interface ) {
                if ( $interface instanceof RakLibInterface ) {
                    try {
                        $reflector = new ReflectionProperty( $interface, "interface" );
                        $reflector->setAccessible( true );
                        $reflector->getValue( $interface )->sendOption( "packetLimit", 900000000000 );
                    } catch ( ReflectionException $e ) {}
                }
            }
            
            // Parse ClientData JWT for API 5
            try {
                [, $clientDataClaims, ] = JwtUtils::parse($packet->clientDataJwt);
                
                if(isset($clientDataClaims["Waterdog_IP"])) {
                    $class = new ReflectionClass($event->getPlayer());

                    $prop = $class->getProperty("ip");
                    $prop->setAccessible(true);
                    $prop->setValue($event->getPlayer(), $clientDataClaims["Waterdog_IP"]);
                }
                if (isset($clientDataClaims["Waterdog_XUID"])) {
                    $class = new ReflectionClass($event->getPlayer());

                    $prop = $class->getProperty("xuid");
                    $prop->setAccessible(true);
                    $prop->setValue($event->getPlayer(), $clientDataClaims["Waterdog_XUID"]);
                    
                    $packetReflection = new ReflectionClass($packet);
                    $packetXuidProperty = $packetReflection->getProperty("xuid");
                    $packetXuidProperty->setAccessible(true);
                    $packetXuidProperty->setValue($packet, $clientDataClaims["Waterdog_XUID"]);
                }
            } catch ( \Exception $e ) {
                $this->getLogger()->debug("Error parsing ClientData JWT: " . $e->getMessage());
            }
        }
    }
    #################################
    #################################
    #################################
}