<?php 
/**
 *
 * @copyright Copyright (c) 2025-<?php echo date('Y'); ?> DC-Soccerplanet.com
 * @author    DC-Soccerplanet.com
 * @license   All Rights Reserved
 *
 * Unauthorized copying, distribution, or modification of this file,
 * via any medium is strictly prohibited without written permission.
 */

/*
 * REALISM UPGRADE SUMMARY:
 * 
 * 1. Dynamic Fatigue System: 
 *    - Introduced a stamina penalty. As 'strengthFreshness' drops, players suffer 
 *      penalties to Passing (accuracy), Tackling (reaction), and Shooting (power/aim).
 * 
 * 2. Attribute Specificity: 
 *    - Tackling is no longer just Strength vs Strength. It is now calculated as 
 *      Defender Strength vs Attacker Technique (Dribbling).
 *    - Passing success relies heavily on Technique rather than average strength.
 * 
 * 3. Situational Awareness (Game State):
 *    - Players now react to the scoreline. If a team is losing, they become more 
 *      aggressive (higher shoot probability, forward passing). 
 *    - If winning comfortably, they play safer (lower shoot probability from distance).
 * 
 * 4. Home Advantage:
 *    - The Home team receives a small calculation bonus in 50/50 duels (tackles) 
 *      to simulate crowd support and familiarity.
 * 
 * 5. Variance (Chaos Factor):
 *    - Added a randomization factor to shooting to allow for "worldies" from weaker 
 *      players and occasional misses from stars.
 * 
 */
class RealisticSimulationStrategy implements ISimulationStrategy {
	
	private $_websoccer;
	private $_passTargetProbPerPosition;
	private $_opponentPositions;
	private $_shootStrengthPerPosition;
	private $_shootProbPerPosition;
	private $_observers;
	
	// Configuration for Realism Math
	const HOME_ADVANTAGE_BONUS = 5; // 5% bonus for home team in duels
	const FATIGUE_PENALTY_MULTIPLIER = 0.4; // How heavily low freshness impacts skill
	
	/**
	 * @param WebSoccer $websoccer context.
	 */
	public function __construct(WebSoccer $websoccer) {
		$this->_websoccer = $websoccer;
		
		$this->_setPassTargetProbabilities();
		
		$this->_setOpponentPositions();
		
		$this->_setShootStrengthPerPosition();
		
		$this->_setShootProbPerPosition();
		
		$this->_observers = array();
	}
	
	/**
	 * Attaches event oberservers which will be called on appropriate events.
	 * 
	 * @param ISimulationObserver $observer observer instance.
	 */
	public function attachObserver(ISimulationObserver $observer) {
		$this->_observers[] = $observer;
	}
	
	/**
	 * @see ISimulationStrategy::kickoff()
	 */
	public function kickoff(SimulationMatch $match) {
		// Realism: Home team usually gets a slight momentum boost at kickoff
		$pHomeTeam[TRUE] = 50 + (self::HOME_ADVANTAGE_BONUS / 2);
		$pHomeTeam[FALSE]  = 100 - $pHomeTeam[TRUE];
		
		$team = SimulationHelper::selectItemFromProbabilities($pHomeTeam) ? $match->homeTeam : $match->guestTeam;
		
		// Kickoff usually goes to midfield, not straight to defense
		$match->setPlayerWithBall(SimulationHelper::selectPlayer($team, PLAYER_POSITION_MIDFIELD, null));
	}

	/**
	 * @see ISimulationStrategy::nextAction()
	 */
	public function nextAction(SimulationMatch $match) {
		$player = $match->getPlayerWithBall();
		
		// goalies can only pass the ball
		if ($player->position == PLAYER_POSITION_GOALY) {
			return 'passBall';
		}
		
		// Probability of attack depends on opponent's formation
		$opponentTeam = SimulationHelper::getOpponentTeam($player, $match);
		$opponentPosition = $this->_opponentPositions[$player->position];
		
		$noOfOwnPlayersInPosition = count($player->team->positionsAndPlayers[$player->position]);
		
		if (isset($opponentTeam->positionsAndPlayers[$opponentPosition])) {
			$noOfOpponentPlayersInPosition = count($opponentTeam->positionsAndPlayers[$opponentPosition]);
		} else {
			$noOfOpponentPlayersInPosition = 0;
		}
		
		// Realism: Tackling probability (Pressure)
		// If the player is outnumbered, pressure increases significantly
		$pTackle = 15;
		if ($noOfOpponentPlayersInPosition > $noOfOwnPlayersInPosition) {
			$pTackle += 15 + 10 * ($noOfOpponentPlayersInPosition - $noOfOwnPlayersInPosition);
		}
		
		// Technical players hold the ball better
		$techBonus = ($player->strengthTech - 50) / 5;
		$pTackle -= $techBonus;

		$pAction['tackle'] = max(5, min($pTackle, 60));
		
		// probability of shooting depends on position + tactic
		$pShoot = $this->_shootProbPerPosition[$player->position];
		$tacticInfluence = ($this->_getOffensiveStrength($player->team, $match) - $this->_getDefensiveStrength($opponentTeam, $match)) / 10;
		
		// reduce number of attempts if own team focussed on counterattacks (unless striker)
		if ($player->team->counterattacks && $player->position !== PLAYER_POSITION_STRIKER) {
			$pShoot = round($pShoot * 0.5);
		}
		
		// Realism: Situational Awareness (Game State)
		$goalDiff = $player->team->getGoals() - $opponentTeam->getGoals();
		
		// Desperation: If losing, shoot more often
		if ($goalDiff < 0) {
			$pShoot += 10; 
		} 
		// Parking the bus: If winning by 2+, keep possession, don't take risky shots
		elseif ($goalDiff >= 2) {
			$pShoot -= 15;
		}

		// Result influence (Morale)
		if ($goalDiff < 0 && $player->team->morale) {
			$pShoot += floor($player->team->morale / 100 * 5);
		}
		
		// forwards/midfielders have a minimum shoot probability
		if ($player->position == PLAYER_POSITION_STRIKER || $player->position == PLAYER_POSITION_MIDFIELD) {
			$minShootProb = 5;
		} else {
			$minShootProb = 1;
		}
		
		$pAction['shoot'] = round(max($minShootProb, min($pShoot + $tacticInfluence, 60)) * $this->_websoccer->getConfig('sim_shootprobability') / 100);
		
		// Remaining probability is passing
		$pAction['passBall'] = 100 - $pAction['tackle'] - $pAction['shoot'] ;
		
		return SimulationHelper::selectItemFromProbabilities($pAction);
	}

	/**
	 * @see ISimulationStrategy::passBall()
	 */
	public function passBall(SimulationMatch $match) {
		$player = $match->getPlayerWithBall();
		
		// Realism: Passing Success depends on Technique and Freshness
		// Fatigue Calculation: (100 - Freshness) * Multiplier
		$fatiguePenalty = (100 - $player->strengthFreshness) * self::FATIGUE_PENALTY_MULTIPLIER;
		
		// Base skill is Technique, weighted with total strength
		$passSkill = ($player->strengthTech * 0.7) + ($player->strength * 0.3);
		$adjustedSkill = $passSkill - $fatiguePenalty;

		$pFailed[FALSE] = round(max(1, min(95, $adjustedSkill)));
		
		// probability of failure increases if long passes are activated
		if ($player->team->longPasses) {
			$pFailed[FALSE] = round($pFailed[FALSE] * 0.75); // 25% penalty for long balls
		}
		
		$pFailed[TRUE] = 100 - $pFailed[FALSE];
		
		// FAILED PASS
		if (SimulationHelper::selectItemFromProbabilities($pFailed) == TRUE) {
			$opponentTeam = SimulationHelper::getOpponentTeam($player, $match);
			
			// Realism: Bad passes usually get intercepted by Midfielders or Defenders
			// Randomly decide if it's intercepted in defense or midfield
			$targetPosition = (rand(0,1) == 1) ? PLAYER_POSITION_MIDFIELD : PLAYER_POSITION_DEFENCE;
			
			// Fallback if that position doesn't exist in opponent team
			if (!isset($opponentTeam->positionsAndPlayers[$targetPosition])) {
				$targetPosition = $this->_opponentPositions[$player->position];
			}
			
			$match->setPlayerWithBall(SimulationHelper::selectPlayer($opponentTeam, $targetPosition, null));
			
			foreach ($this->_observers as $observer) {
				$observer->onBallPassFailure($match, $player);
			}
			
			return FALSE;
		}
		
		// SUCCESSFUL PASS
		// compute probabilities for target position
		$pTarget = $this->_passTargetProbPerPosition[$player->position];

		// Realism: Situational Passing
		$goalDiff = $player->team->getGoals() - SimulationHelper::getOpponentTeam($player, $match)->getGoals();
		
		// If losing, pump ball to strikers
		if ($goalDiff < 0) {
			$pTarget[PLAYER_POSITION_STRIKER] += 20;
			$pTarget[PLAYER_POSITION_DEFENCE] = 0; // Stop backpassing
		}
		// If winning, play safe (more defense/midfield passing)
		elseif ($goalDiff > 0) {
			$pTarget[PLAYER_POSITION_STRIKER] -= 10;
			$pTarget[PLAYER_POSITION_DEFENCE] += 10;
		}
		
		// consider tactic option: long passes
		if ($player->team->longPasses) {
			$pTarget[PLAYER_POSITION_STRIKER] += 15;
		}
		
		$offensiveInfluence = round(10 - $player->team->offensive * 0.2);
		$pTarget[PLAYER_POSITION_DEFENCE] = $pTarget[PLAYER_POSITION_DEFENCE] + $offensiveInfluence;
		
		// Recalculate midfield based on remaining percentage to ensure 100% sum
		$pTarget[PLAYER_POSITION_MIDFIELD] = max(0, 100 - $pTarget[PLAYER_POSITION_STRIKER] - $pTarget[PLAYER_POSITION_DEFENCE] - $pTarget[PLAYER_POSITION_GOALY]);
		
		// select target position
		$targetPosition = SimulationHelper::selectItemFromProbabilities($pTarget);
		
		// select player
		$match->setPlayerWithBall(SimulationHelper::selectPlayer($player->team, $targetPosition, $player));
		
		foreach ($this->_observers as $observer) {
			$observer->onBallPassSuccess($match, $player);
		}
		return TRUE;
	}

	/**
	 * Computes a tackle between the player with ball and an opponent player who will be picked by this implementation.
	 * Also triggers yellow/red cards, as well as injuries and penalties.
	 * 
	 * @see ISimulationStrategy::tackle()
	 */
	public function tackle(SimulationMatch $match) {
		$player = $match->getPlayerWithBall();
		
		$opponentTeam = SimulationHelper::getOpponentTeam($player, $match);
		$targetPosition = $this->_opponentPositions[$player->position];
		$opponent = SimulationHelper::selectPlayer($opponentTeam, $targetPosition, null);
		
		// Realism: Duel Calculation
		// Attacker uses Technique (Dribbling) vs Defender Strength/Tackling
		// Both are affected by fatigue
		
		$attFatigue = (100 - $player->strengthFreshness) * self::FATIGUE_PENALTY_MULTIPLIER;
		$defFatigue = (100 - $opponent->strengthFreshness) * self::FATIGUE_PENALTY_MULTIPLIER;
		
		// Attacker Score: 60% Tech, 40% Strength
		$attScore = ($player->strengthTech * 0.6 + $player->getTotalStrength($this->_websoccer, $match) * 0.4) - $attFatigue;
		
		// Defender Score: 70% Strength, 30% Tech
		$defScore = ($opponent->getTotalStrength($this->_websoccer, $match) * 0.7 + $opponent->strengthTech * 0.3) - $defFatigue;
		
		// Home Advantage for the Defender
		if ($opponent->team->id == $match->homeTeam->id) {
			$defScore += self::HOME_ADVANTAGE_BONUS;
		}
		
		// can win?
		$winProb = 50 + ($attScore - $defScore);
		
		$pWin[TRUE] = max(5, min($winProb, 95));
		$pWin[FALSE] = 100 - $pWin[TRUE];
		
		$result = SimulationHelper::selectItemFromProbabilities($pWin);
		
		foreach ($this->_observers as $observer) {
			$observer->onAfterTackle($match, ($result) ? $player : $opponent, ($result) ? $opponent : $player);
		}
		
		// player can keep the ball.
		if ($result == TRUE) {
			// Realism: Tired defenders make more fouls
			$tirednessInfluence = (100 - $opponent->strengthFreshness) / 5;
			
			// opponent: yellow / redcard
			$pTackle['yellow'] = round(max(1, min(20, round((100 - $opponent->strengthTech) / 2))) * $this->_websoccer->getConfig('sim_cardsprobability') / 100);
			
			// Add tiredness to foul probability
			$pTackle['yellow'] += $tirednessInfluence;
			
			// prevent too many yellow-red cards if already booked (Caution logic)
			if ($opponent->yellowCards > 0) {
				$pTackle['yellow'] = round($pTackle['yellow'] / 3);
			}
			
			$pTackle['red'] = 0;
			// if chances for yellow card is very high, red card chance appears
			if ($pTackle['yellow']  > 25) {
				$pTackle['red'] = 2;
			}
			
			$pTackle['fair'] = 100 - $pTackle['yellow'] - $pTackle['red'];
			
			$tackled = SimulationHelper::selectItemFromProbabilities($pTackle);
			if ($tackled == 'yellow' || $tackled == 'red') {
				
				// player might have injury (Fragile players get injured easier)
				$injuryBase = (100 - $player->strengthFreshness) / 2; // Low freshness = high risk
				$pInjured[TRUE] = min(80, round($injuryBase * $this->_websoccer->getConfig('sim_injuredprobability') / 100));
				$pInjured[FALSE] = 100 - $pInjured[TRUE];
				$injured = SimulationHelper::selectItemFromProbabilities($pInjured);
				$blockedMatches = 0;
				if ($injured) {
					$maxMatchesInjured = (int) $this->_websoccer->getConfig('sim_maxmatches_injured');
					// Use a random range for injury length
					$blockedMatches = rand(1, $maxMatchesInjured);
				}			
				
				foreach ($this->_observers as $observer) {
					if ($tackled == 'yellow') {
						$observer->onYellowCard($match, $opponent);
					} else {
						
						// number of blocked matches
						$maxMatchesBlocked = (int) $this->_websoccer->getConfig('sim_maxmatches_blocked');
						$blockedMatchesRedCard = rand(1, $maxMatchesBlocked);
						$observer->onRedCard($match, $opponent, $blockedMatchesRedCard);
					}
					
					if ($injured) {
						$observer->onInjury($match, $player, $blockedMatches);
						
						// select another player to take the ball
						$match->setPlayerWithBall(SimulationHelper::selectPlayer($player->team, PLAYER_POSITION_MIDFIELD));
					}
				}
				
				// if player is a striker, he might be fouled within the goal room -> penalty
				// Realism: 15% of striker fouls are penalties
				if ($player->position == PLAYER_POSITION_STRIKER) {
					$pPenalty[TRUE] = 15;
					$pPenalty[FALSE] = 85;
					if (SimulationHelper::selectItemFromProbabilities($pPenalty)) {
						$this->foulPenalty($match, $player->team);
					}
					
					// fouls on all other player lead to a free kick
				} else {
					
					// select player who will shoot
					if ($player->team->freeKickPlayer != NULL) {
						$freeKickScorer = $player->team->freeKickPlayer;
					} else {
						$freeKickScorer = SimulationHelper::selectPlayer($player->team, PLAYER_POSITION_MIDFIELD);
					}
					
					// get goaly influence
					$goaly = SimulationHelper::selectPlayer(SimulationHelper::getOpponentTeam($freeKickScorer, $match), PLAYER_POSITION_GOALY, null);
					$goalyInfluence = (int) $this->_websoccer->getConfig('sim_goaly_influence');
					$shootReduction = round($goaly->getTotalStrength($this->_websoccer, $match) * $goalyInfluence/100);
					
					// Realism: Free kicks rely on Technique
					$shootStrength = ($freeKickScorer->strengthTech * 0.8) + ($freeKickScorer->strength * 0.2);
					
					$pGoal[TRUE] = max(5, min($shootStrength - $shootReduction, 60));
					$pGoal[FALSE] = 100 - $pGoal[TRUE];
					
					$freeKickResult = SimulationHelper::selectItemFromProbabilities($pGoal);
					foreach ($this->_observers as $observer) {
						$observer->onFreeKick($match, $freeKickScorer, $goaly, $freeKickResult);
					}
					
					if ($freeKickResult) {
						$this->_kickoff($match, $freeKickScorer);
					} else {
						$match->setPlayerWithBall($goaly);
					}
					
				}

			}
			
			// player lost the ball
		} else {
			
			$match->setPlayerWithBall($opponent);
			
			// try a counterattack if player who lost the ball was an attacking player and if team is supposed to focus on counterattacks.
			if ($player->position == PLAYER_POSITION_STRIKER && $opponent->team->counterattacks) {
				
				// Realism: Counter success depends on offensive stat and technical ability of the defender stealing it
				$counterAttempt[TRUE] = ($player->team->offensive / 2) + ($opponent->strengthTech / 2);
				$counterAttempt[FALSE] = 100 - $counterAttempt[TRUE];
				
				if (SimulationHelper::selectItemFromProbabilities($counterAttempt)) {
					// first pass to a striker if player is defender
					if ($opponent->position == PLAYER_POSITION_DEFENCE) {
						$striker = SimulationHelper::selectPlayer($opponent->team, PLAYER_POSITION_STRIKER);
						if ($striker) {
							$match->setPlayerWithBall($striker);
							// High chance to shoot immediately on counter
							if (rand(0, 100) < 40) {
								$this->shoot($match);
							}
							return $result;
						}
					}
					$this->shoot($match);
				}
			}
			
		}
		
		return $result;
	}

	/**
	 * @see ISimulationStrategy::shoot()
	 */
	public function shoot(SimulationMatch $match) {
		$player = $match->getPlayerWithBall();
		$goaly = SimulationHelper::selectPlayer(SimulationHelper::getOpponentTeam($player, $match), PLAYER_POSITION_GOALY, null);
		
		// get goaly influence from settings. 20 = 20%
		$goalyInfluence = (int) $this->_websoccer->getConfig('sim_goaly_influence');
		$shootReduction = round($goaly->getTotalStrength($this->_websoccer, $match) * $goalyInfluence/100);
		
		// increase / reduce shooting strength by position
		$shootStrength = round($player->getTotalStrength($this->_websoccer, $match) * $this->_shootStrengthPerPosition[$player->position] / 100);
		
		// Realism: Fatigue Check
		$fatiguePenalty = (100 - $player->strengthFreshness) * self::FATIGUE_PENALTY_MULTIPLIER;
		$shootStrength -= $fatiguePenalty;
		
		// increase chance with every failed attempt (Sighting in)
		if ($player->position != PLAYER_POSITION_STRIKER || 
				isset($player->team->positionsAndPlayers[PLAYER_POSITION_STRIKER]) 
				&& count($player->team->positionsAndPlayers[PLAYER_POSITION_STRIKER]) > 1) {
			$shootStrength = $shootStrength + $player->getShoots() * 2 - $player->getGoals();
		}
		
		// reduce probability of too many goals per scorer (balancing)
		if ($player->getGoals() > 1) {
			$shootStrength = round($shootStrength / ($player->getGoals() * 0.8));
		}
		
		// Realism: Chaos Factor (Luck)
		// Generates a number between -8 and +8
		$chaos = rand(-8, 8);
		
		$pGoal[TRUE] = max(2, min(($shootStrength + $chaos) - $shootReduction, 70));
		$pGoal[FALSE] = 100 - $pGoal[TRUE];
		
		$result = SimulationHelper::selectItemFromProbabilities($pGoal);
		
		// missed
		if ($result == FALSE) {
			foreach ($this->_observers as $observer) {
				$observer->onShootFailure($match, $player, $goaly);
			}
			
			// always give ball to goaly
			$match->setPlayerWithBall($goaly);
			
			// resulted in a corner? Depends on player's strength/pressure
			// Realism: Better teams force more corners
			$pCorner[TRUE] = 20 + ($player->team->offensive / 5);
			$pCorner[FALSE] = 100 - $pCorner[TRUE];
			
			if (SimulationHelper::selectItemFromProbabilities($pCorner)) {
				
				// select players
				if ($player->team->freeKickPlayer) {
					$passingPlayer = $player->team->freeKickPlayer;
				} else {
					$passingPlayer = SimulationHelper::selectPlayer($player->team, PLAYER_POSITION_MIDFIELD);
				}
				
				// Target tallest/strongest (Generic selection here)
				$targetPlayer = SimulationHelper::selectPlayer($player->team, PLAYER_POSITION_MIDFIELD, $passingPlayer);
				foreach ($this->_observers as $observer) {
					$observer->onCorner($match, $passingPlayer, $targetPlayer);
				}
				$match->setPlayerWithBall($targetPlayer);
				
				// Realism: Small chance of direct header goal from corner
				if (rand(0,100) < 15) {
					$this->shoot($match);
				}
			}
			
		// scored
		} else {
			foreach ($this->_observers as $observer) {
				$observer->onGoal($match, $player, $goaly);
			}
			$this->_kickoff($match, $player);
		}
		
		return $result;
	}
	
	/**
	 * @see ISimulationStrategy::penaltyShooting()
	 */
	public function penaltyShooting(SimulationMatch $match) {
		$shots = 0;
		$goalsHome = 0;
		$goalsGuest = 0;
		
		$playersHome = SimulationHelper::getPlayersForPenaltyShooting($match->homeTeam);
		$playersGuest = SimulationHelper::getPlayersForPenaltyShooting($match->guestTeam);
		
		$playerIndexHome = 0;
		$playerIndexGuest = 0;
		
		// Realism: Cap shots to avoid infinite loops
		while ($shots <= 30) {
			$shots++;
			
			// home team shoots
			if ($this->_shootPenalty($match, $playersHome[$playerIndexHome])) {
				$goalsHome++;
			}
			
			// guest team shoots
			if ($this->_shootPenalty($match, $playersGuest[$playerIndexGuest])) {
				$goalsGuest++;
			}
			
			// do we have a winner?
			if ($shots >= 5 && $goalsHome !== $goalsGuest) {
				return TRUE;
			}
			
			$playerIndexHome++;
			$playerIndexGuest++;
			
			if ($playerIndexHome >= count($playersHome)) {
				$playerIndexHome = 0;
			}
			if ($playerIndexGuest >= count($playersGuest)) {
				$playerIndexGuest = 0;
			}
		}
		return TRUE;
	}
	
	private function foulPenalty(SimulationMatch $match, SimulationTeam $team) {
		// select player to shoot (strongest)
		$players = SimulationHelper::getPlayersForPenaltyShooting($team);
		$player = $players[0];
		
		$match->setPlayerWithBall($player);
		
		// execute shoot
		if ($this->_shootPenalty($match, $player)) {
			
			// if hit, only update player's statistic
			$player->setGoals($player->getGoals() + 1);
		} else {
			// choose goaly as next player
			$goaly = SimulationHelper::selectPlayer(SimulationHelper::getOpponentTeam($player, $match), PLAYER_POSITION_GOALY, null);
			$match->setPlayerWithBall($goaly);
		}
	}
	
	private function _setPassTargetProbabilities() {
		// Updated probabilities for more modern playstyle (less passing back to goalie)
		$this->_passTargetProbPerPosition[PLAYER_POSITION_GOALY][PLAYER_POSITION_GOALY] = 0;
		$this->_passTargetProbPerPosition[PLAYER_POSITION_GOALY][PLAYER_POSITION_DEFENCE] = 60;
		$this->_passTargetProbPerPosition[PLAYER_POSITION_GOALY][PLAYER_POSITION_MIDFIELD] = 35;
		$this->_passTargetProbPerPosition[PLAYER_POSITION_GOALY][PLAYER_POSITION_STRIKER] = 5;
	
		$this->_passTargetProbPerPosition[PLAYER_POSITION_DEFENCE][PLAYER_POSITION_GOALY] = 5;
		$this->_passTargetProbPerPosition[PLAYER_POSITION_DEFENCE][PLAYER_POSITION_DEFENCE] = 25;
		$this->_passTargetProbPerPosition[PLAYER_POSITION_DEFENCE][PLAYER_POSITION_MIDFIELD] = 60;
		$this->_passTargetProbPerPosition[PLAYER_POSITION_DEFENCE][PLAYER_POSITION_STRIKER] = 10;
	
		$this->_passTargetProbPerPosition[PLAYER_POSITION_MIDFIELD][PLAYER_POSITION_GOALY] = 0;
		$this->_passTargetProbPerPosition[PLAYER_POSITION_MIDFIELD][PLAYER_POSITION_DEFENCE] = 20;
		$this->_passTargetProbPerPosition[PLAYER_POSITION_MIDFIELD][PLAYER_POSITION_MIDFIELD] = 50;
		$this->_passTargetProbPerPosition[PLAYER_POSITION_MIDFIELD][PLAYER_POSITION_STRIKER] = 30;
	
		$this->_passTargetProbPerPosition[PLAYER_POSITION_STRIKER][PLAYER_POSITION_GOALY] = 0;
		$this->_passTargetProbPerPosition[PLAYER_POSITION_STRIKER][PLAYER_POSITION_DEFENCE] = 5;
		$this->_passTargetProbPerPosition[PLAYER_POSITION_STRIKER][PLAYER_POSITION_MIDFIELD] = 45;
		$this->_passTargetProbPerPosition[PLAYER_POSITION_STRIKER][PLAYER_POSITION_STRIKER] = 50;
	}
	
	private function _setOpponentPositions() {
		$this->_opponentPositions[PLAYER_POSITION_GOALY] = PLAYER_POSITION_STRIKER;
		$this->_opponentPositions[PLAYER_POSITION_DEFENCE] = PLAYER_POSITION_STRIKER;
		$this->_opponentPositions[PLAYER_POSITION_MIDFIELD] = PLAYER_POSITION_MIDFIELD;
		$this->_opponentPositions[PLAYER_POSITION_STRIKER] = PLAYER_POSITION_DEFENCE;
	}
	
	private function _setShootProbPerPosition() {
		$this->_shootProbPerPosition[PLAYER_POSITION_GOALY] = 0;
		$this->_shootProbPerPosition[PLAYER_POSITION_DEFENCE] = 3;
		$this->_shootProbPerPosition[PLAYER_POSITION_MIDFIELD] = 15;
		$this->_shootProbPerPosition[PLAYER_POSITION_STRIKER] = 40;
	}
	
	private function _setShootStrengthPerPosition() {
		$this->_shootStrengthPerPosition[PLAYER_POSITION_GOALY] = 10;
		$this->_shootStrengthPerPosition[PLAYER_POSITION_DEFENCE] = $this->_websoccer->getConfig('sim_shootstrength_defense');
		$this->_shootStrengthPerPosition[PLAYER_POSITION_MIDFIELD] = $this->_websoccer->getConfig('sim_shootstrength_midfield');
		$this->_shootStrengthPerPosition[PLAYER_POSITION_STRIKER] = $this->_websoccer->getConfig('sim_shootstrength_striker');
	}
	
	private function _getOffensiveStrength($team, $match) {
		
		$strength = 0;
		
		// midfield
		if (isset($team->positionsAndPlayers[PLAYER_POSITION_MIDFIELD])) {
			
			$omPlayers = 0;
			foreach ($team->positionsAndPlayers[PLAYER_POSITION_MIDFIELD] as $player) {
				$mfStrength = $player->getTotalStrength($this->_websoccer, $match);
				
				// add 30% for attacking midfielders, reduce 30% if defensive
				if ($player->mainPosition == 'OM') {
					$omPlayers++;
					// only up to 3 OMs are effective. Else, players in defense are missing for building attacks
					if ($omPlayers <= 3) {
						$mfStrength = $mfStrength * 1.3;
					} else {
						$mfStrength = $mfStrength * 0.5;
					}
					
				} else if ($player->mainPosition == 'DM') {
					$mfStrength = $mfStrength * 0.7;
				}
				
				$strength += $mfStrength;
			}
		}
		
		// strikers (count only first two doubled since too many strikers are inefficient)
		$noOfStrikers = 0;
		if (isset($team->positionsAndPlayers[PLAYER_POSITION_STRIKER])) {
			foreach ($team->positionsAndPlayers[PLAYER_POSITION_STRIKER] as $player) {
				$noOfStrikers++;
				
				if ($noOfStrikers < 3) {
					$strength += $player->getTotalStrength($this->_websoccer, $match) * 1.5;
				} else {
					$strength += $player->getTotalStrength($this->_websoccer, $match) * 0.5;
				}
			}
		}
		
		$offensiveFactor = (80 + $team->offensive * 0.4) / 100;
		$strength = $strength * $offensiveFactor;
		
		return $strength;
	}
	
	private function _getDefensiveStrength(SimulationTeam $team, $match) {
	
		$strength = 0;
	
		// midfield
		foreach ($team->positionsAndPlayers[PLAYER_POSITION_MIDFIELD] as $player) {
			$mfStrength = $player->getTotalStrength($this->_websoccer, $match);
			
			// add 30% for defensive midfielders, reduce 30% if attacking
			if ($player->mainPosition == 'OM') {
				$mfStrength = $mfStrength * 0.7;
			} else if ($player->mainPosition == 'DM') {
				$mfStrength = $mfStrength * 1.3;
			}
			
			// give bonus on midfielders when team is supposed to be focussed on counterattacks. They will be more defending then.
			if ($team->counterattacks) {
				$mfStrength = $mfStrength * 1.1;
			}
			
			$strength += $mfStrength;
		}
	
		// defense
		$noOfDefence = 0;
		foreach ($team->positionsAndPlayers[PLAYER_POSITION_DEFENCE] as $player) {
			$noOfDefence++;
			$strength += $player->getTotalStrength($this->_websoccer, $match);
		}
		
		// less than 3 defence players would be extra risky
		if ($noOfDefence < 3) {
			$strength = $strength * 0.5;
			
			// but more than 4 extra secure
		} else if ($noOfDefence > 4) {
			$strength = $strength * 1.5;
		}
	
		// tactic
		$offensiveFactor = (130 - $team->offensive * 0.5) / 100;
		$strength = $strength * $offensiveFactor;
		
		return $strength;
	}
	
	private function _shootPenalty(SimulationMatch $match, SimulationPlayer $player) {
		
		$goaly = SimulationHelper::selectPlayer(SimulationHelper::getOpponentTeam($player, $match), PLAYER_POSITION_GOALY, null);
		
		// get goaly influence from settings. 20 = 20%
		$goalyInfluence = (int) $this->_websoccer->getConfig('sim_goaly_influence');
		$shootReduction = round($goaly->getTotalStrength($this->_websoccer, $match) * $goalyInfluence/100);
		
		// Realism: Penalty is mostly Tech + Mental (Strength used as proxy for mental here)
		$penaltySkill = ($player->strengthTech * 0.6) + ($player->strength * 0.4);
		
		// probability is between 40 and 90%
		$pGoal[TRUE] = max(40, min($penaltySkill - $shootReduction, 90));
		$pGoal[FALSE] = 100 - $pGoal[TRUE];
		
		$result = SimulationHelper::selectItemFromProbabilities($pGoal);
		
		foreach ($this->_observers as $observer) {
			$observer->onPenaltyShoot($match, $player, $goaly, $result);
		}
		
		return $result;
	}
	
	private function _kickoff(SimulationMatch $match, SimulationPlayer $scorer) {
		// let kick-off the opponent
		// Realism: Restart from Midfield, not defense
		$match->setPlayerWithBall(
				SimulationHelper::selectPlayer(SimulationHelper::getOpponentTeam($scorer, $match), PLAYER_POSITION_MIDFIELD, null));
	}
	
}
?>