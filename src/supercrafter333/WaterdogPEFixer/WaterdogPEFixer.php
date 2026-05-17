<?php

namespace supercrafter333\WaterdogPEFixer;

use pocketmine\event\Listener;
use pocketmine\event\player\PlayerLoginEvent;
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
    /** @var array<string, array{ip?: string, xuid?: string}> */
    private array $waterdogData = [];

    public function onEnable(): void
    {
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
    }

    #################################
    ##[Fix Waterdog(PE) IP & XUID ]##
    #################################

    /**
     * STEP 1: Extract Waterdog data from LoginPacket
     * Runs during packet reception (before Player is created)
     */
    public function onPacketReceive(DataPacketReceiveEvent $event): void
    {
        $packet = $event->getPacket();
        if (!$packet instanceof LoginPacket) {
            return;
        }

        try {
            // Increase packet limit for Waterdog
            foreach ($this->getServer()->getNetwork()->getInterfaces() as $interface) {
                if ($interface instanceof RakLibInterface) {
                    try {
                        $reflector = new ReflectionProperty($interface, "interface");
                        $reflector->setAccessible(true);
                        $reflector->getValue($interface)->sendOption("packetLimit", 900000000000);
                    } catch (ReflectionException $e) {}
                }
            }

            // Parse ClientData JWT (API 5 method)
            [, $clientDataClaims, ] = JwtUtils::parse($packet->clientDataJwt);

            // Extract Waterdog custom data
            $waterdogData = [
                'ip' => $clientDataClaims['Waterdog_IP'] ?? null,
                'xuid' => $clientDataClaims['Waterdog_XUID'] ?? null,
            ];

            // Store for later retrieval in PlayerLoginEvent
            // Using object hash as key (temporary storage during login)
            $sessionKey = spl_object_hash($event->getOrigin());
            $this->waterdogData[$sessionKey] = $waterdogData;
        } catch (\Exception $e) {
            $this->getLogger()->debug("Error parsing ClientData JWT: " . $e->getMessage());
        }
    }

    /**
     * STEP 2: Apply Waterdog data to Player (Player now exists)
     * Runs after Player object is created
     */
    public function onPlayerLogin(PlayerLoginEvent $event): void
    {
        $player = $event->getPlayer();

        // Get most recent Waterdog data (queue approach)
        if (empty($this->waterdogData)) {
            return;
        }

        // Pop the first/most likely matching data
        $waterdogData = array_pop($this->waterdogData);

        if ($waterdogData['ip'] !== null) {
            try {
                $class = new ReflectionClass($player);
                $prop = $class->getProperty("ip");
                $prop->setAccessible(true);
                $prop->setValue($player, $waterdogData['ip']);

                $this->getLogger()->debug("Set IP for {$player->getName()}: {$waterdogData['ip']}");
            } catch (ReflectionException $e) {
                $this->getLogger()->debug("Could not set IP: " . $e->getMessage());
            }
        }

        if ($waterdogData['xuid'] !== null) {
            try {
                $class = new ReflectionClass($player);
                $prop = $class->getProperty("xuid");
                $prop->setAccessible(true);
                $prop->setValue($player, $waterdogData['xuid']);

                $this->getLogger()->debug("Set XUID for {$player->getName()}: {$waterdogData['xuid']}");
            } catch (ReflectionException $e) {
                $this->getLogger()->debug("Could not set XUID: " . $e->getMessage());
            }
        }
    }
    #################################
    #################################
    #################################
}
