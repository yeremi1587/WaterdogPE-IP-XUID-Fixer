<?php

namespace supercrafter333\WaterdogPEFixer;

use pocketmine\event\Listener;
use pocketmine\event\player\PlayerLoginEvent;
use pocketmine\event\player\PlayerPreLoginEvent;
use pocketmine\event\player\PlayerCreationEvent;
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
    /** @var array<string, array{ip?: string, xuid?: string, username?: string}> */
    private array $waterdogData = [];
    /** @var array<string, string> */
    private array $waterdogSessionByUsername = [];

    public function onEnable(): void
    {
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
        $this->getLogger()->info("WaterdogPEFixer enabled");
    }
    public function onDataPacketReceive(DataPacketReceiveEvent $event): void
    {
        $packet = $event->getPacket();
        if (!$packet instanceof LoginPacket) {
            return;
        }

        try {
            foreach ($this->getServer()->getNetwork()->getInterfaces() as $interface) {
                if ($interface instanceof RakLibInterface) {
                    try {
                        $reflector = new ReflectionProperty($interface, "interface");
                        $reflector->setAccessible(true);
                        $reflector->getValue($interface)->sendOption("packetLimit", 900000000000);
                    } catch (ReflectionException $e) {}
                }
            }

            [, $clientDataClaims, ] = JwtUtils::parse($packet->clientDataJwt);
            $this->getLogger()->info("Login packet clientDataJwt parsed, found Waterdog_IP=" . ($clientDataClaims['Waterdog_IP'] ?? $clientDataClaims['Waterdog IP'] ?? 'null') . ", Waterdog_XUID=" . ($clientDataClaims['Waterdog_XUID'] ?? $clientDataClaims['Waterdog XUID'] ?? 'null'));

            $this->getLogger()->debug("clientDataClaims keys: " . implode(", ", array_keys($clientDataClaims)));

            $waterdogData = [
                'ip' => $clientDataClaims['Waterdog_IP'] ?? $clientDataClaims['Waterdog IP'] ?? null,
                'xuid' => $clientDataClaims['Waterdog_XUID'] ?? $clientDataClaims['Waterdog XUID'] ?? null,
                'username' => $clientDataClaims['DisplayName'] ?? null,
            ];

            if ($waterdogData['ip'] !== null) {
                try {
                    $session = $event->getOrigin();
                    $sessionRef = new ReflectionClass($session);
                    $ipProp = $sessionRef->getProperty('ip');
                    $ipProp->setAccessible(true);
                    $ipProp->setValue($session, $waterdogData['ip']);
                    $this->getLogger()->info("Overrode NetworkSession IP to " . $waterdogData['ip']);
                } catch (ReflectionException $e) {
                    $this->getLogger()->debug('Could not override NetworkSession IP: ' . $e->getMessage());
                }
            }

            $sessionKey = spl_object_hash($event->getOrigin());
            $this->waterdogData[$sessionKey] = $waterdogData;
            if (!empty($waterdogData['username'])) {
                $this->waterdogSessionByUsername[strtolower($waterdogData['username'])] = $sessionKey;
            }
        } catch (\Exception $e) {
            $this->getLogger()->debug("Error parsing ClientData JWT: " . $e->getMessage());
        }
    }

    public function onPlayerPreLogin(PlayerPreLoginEvent $event): void
    {
        $username = strtolower($event->getPlayerInfo()->getUsername());
        if (!isset($this->waterdogSessionByUsername[$username])) {
            return;
        }

        $sessionKey = $this->waterdogSessionByUsername[$username];
        if (!isset($this->waterdogData[$sessionKey])) {
            return;
        }

        $waterdogData = $this->waterdogData[$sessionKey];

        if ($waterdogData['ip'] !== null) {
            try {
                $eventRef = new ReflectionClass($event);
                $ipProp = $eventRef->getProperty('ip');
                $ipProp->setAccessible(true);
                $ipProp->setValue($event, $waterdogData['ip']);
                $this->getLogger()->info("onPlayerPreLogin: overridden event IP to " . $waterdogData['ip']);
            } catch (ReflectionException $e) {
                $this->getLogger()->debug('Could not override PlayerPreLoginEvent IP: ' . $e->getMessage());
            }
        }
    }

    public function onPlayerCreation(PlayerCreationEvent $event): void
    {
        $session = $event->getNetworkSession();
        $sessionKey = spl_object_hash($session);
        if (!isset($this->waterdogData[$sessionKey])) {
            return;
        }

        $waterdogData = $this->waterdogData[$sessionKey];
        if ($waterdogData['ip'] !== null) {
            try {
                $sessionRef = new ReflectionClass($session);
                $ipProp = $sessionRef->getProperty('ip');
                $ipProp->setAccessible(true);
                $ipProp->setValue($session, $waterdogData['ip']);
                $this->getLogger()->info("onPlayerCreation: overridden session IP to " . $waterdogData['ip']);
            } catch (ReflectionException $e) {
                $this->getLogger()->debug('Could not override NetworkSession IP in PlayerCreationEvent: ' . $e->getMessage());
            }
        }
    }
    public function onPlayerLogin(PlayerLoginEvent $event): void
    {
        $player = $event->getPlayer();

        $sessionKey = spl_object_hash($player->getNetworkSession());
        if (!isset($this->waterdogData[$sessionKey])) {
            return;
        }

        $waterdogData = $this->waterdogData[$sessionKey];
        unset($this->waterdogData[$sessionKey]);

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
            $this->getLogger()->info("Player session ip after player ip set: " . $player->getNetworkSession()->getIp());
            try {
                $session = $player->getNetworkSession();
                $sessionRef = new ReflectionClass($session);
                $ipProp = $sessionRef->getProperty('ip');
                $ipProp->setAccessible(true);
                $ipProp->setValue($session, $waterdogData['ip']);
            } catch (ReflectionException $e) {
                $this->getLogger()->debug('Could not set NetworkSession IP: ' . $e->getMessage());
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
