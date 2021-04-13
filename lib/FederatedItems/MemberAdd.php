<?php

declare(strict_types=1);


/**
 * Circles - Bring cloud-users closer together.
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Maxence Lange <maxence@artificial-owl.com>
 * @copyright 2021
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */


namespace OCA\Circles\FederatedItems;


use daita\MySmallPhpTools\Exceptions\InvalidItemException;
use daita\MySmallPhpTools\Exceptions\RequestNetworkException;
use daita\MySmallPhpTools\Exceptions\SignatoryException;
use daita\MySmallPhpTools\Traits\Nextcloud\nc22\TNC22Logger;
use daita\MySmallPhpTools\Traits\TStringTools;
use Exception;
use OC\User\NoUserException;
use OCA\Circles\Db\MemberRequest;
use OCA\Circles\Exceptions\CircleNotFoundException;
use OCA\Circles\Exceptions\FederatedItemBadRequestException;
use OCA\Circles\Exceptions\FederatedItemException;
use OCA\Circles\Exceptions\FederatedItemNotFoundException;
use OCA\Circles\Exceptions\FederatedItemRemoteException;
use OCA\Circles\Exceptions\FederatedItemServerException;
use OCA\Circles\Exceptions\FederatedUserException;
use OCA\Circles\Exceptions\FederatedUserNotFoundException;
use OCA\Circles\Exceptions\InvalidIdException;
use OCA\Circles\Exceptions\MemberNotFoundException;
use OCA\Circles\Exceptions\OwnerNotFoundException;
use OCA\Circles\Exceptions\RemoteInstanceException;
use OCA\Circles\Exceptions\RemoteNotFoundException;
use OCA\Circles\Exceptions\RemoteResourceNotFoundException;
use OCA\Circles\Exceptions\TokenDoesNotExistException;
use OCA\Circles\Exceptions\UnknownRemoteException;
use OCA\Circles\Exceptions\UserTypeNotFoundException;
use OCA\Circles\IFederatedItem;
use OCA\Circles\IFederatedItemAsyncProcess;
use OCA\Circles\IFederatedItemMemberCheckNotRequired;
use OCA\Circles\IFederatedItemMemberRequired;
use OCA\Circles\IFederatedUser;
use OCA\Circles\Model\Circle;
use OCA\Circles\Model\DeprecatedCircle;
use OCA\Circles\Model\DeprecatedMember;
use OCA\Circles\Model\Federated\FederatedEvent;
use OCA\Circles\Model\FederatedUser;
use OCA\Circles\Model\Helpers\MemberHelper;
use OCA\Circles\Model\ManagedModel;
use OCA\Circles\Model\Member;
use OCA\Circles\Model\SharesToken;
use OCA\Circles\Service\CircleService;
use OCA\Circles\Service\ConfigService;
use OCA\Circles\Service\EventService;
use OCA\Circles\Service\FederatedUserService;
use OCA\Circles\StatusCode;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Mail\IEMailTemplate;
use OCP\Util;


/**
 * Class MemberAdd
 *
 * @package OCA\Circles\GlobalScale
 */
class MemberAdd implements
	IFederatedItem,
	IFederatedItemAsyncProcess,
	IFederatedItemMemberRequired,
	IFederatedItemMemberCheckNotRequired {


	use TStringTools;
	use TNC22Logger;


	/** @var IUserManager */
	private $userManager;

	/** @var MemberRequest */
	private $memberRequest;

	/** @var FederatedUserService */
	private $federatedUserService;

	/** @var CircleService */
	private $circleService;

	/** @var EventService */
	private $eventService;

	/** @var ConfigService */
	private $configService;


	/**
	 * MemberAdd constructor.
	 *
	 * @param IUserManager $userManager
	 * @param MemberRequest $memberRequest
	 * @param FederatedUserService $federatedUserService
	 * @param CircleService $circleService
	 * @param EventService $eventService
	 * @param ConfigService $configService
	 */
	public function __construct(
		IUserManager $userManager, MemberRequest $memberRequest, FederatedUserService $federatedUserService,
		CircleService $circleService, EventService $eventService, ConfigService $configService
	) {
		$this->userManager = $userManager;
		$this->memberRequest = $memberRequest;
		$this->federatedUserService = $federatedUserService;
		$this->circleService = $circleService;
		$this->eventService = $eventService;
		$this->configService = $configService;
	}


	/**
	 * @param FederatedEvent $event
	 *
	 * @throws FederatedItemBadRequestException
	 * @throws FederatedItemNotFoundException
	 * @throws FederatedItemServerException
	 * @throws FederatedItemRemoteException
	 * @throws FederatedItemException
	 */
	public function verify(FederatedEvent $event): void {
		$member = $event->getMember();
		$circle = $event->getCircle();
		$initiator = $circle->getInitiator();

		$initiatorHelper = new MemberHelper($initiator);
		$initiatorHelper->mustBeModerator();

		try {
			if ($member->getSingleId() !== '') {
				$userId = $member->getSingleId() . '@' . $member->getInstance();
				$federatedUser = $this->federatedUserService->getFederatedUser($userId, Member::TYPE_SINGLE);
			} else {
				$userId = $member->getUserId() . '@' . $member->getInstance();
				$federatedUser =
					$this->federatedUserService->getFederatedUser($userId, $member->getUserType());
			}

		} catch (MemberNotFoundException $e) {
			throw new FederatedItemBadRequestException(StatusCode::$MEMBER_ADD[120], 120);
		}

		if ($federatedUser->getBasedOn()->isConfig(Circle::CFG_ROOT)) {
			throw new FederatedItemBadRequestException(StatusCode::$MEMBER_ADD[125], 125);
		}

		$member->importFromIFederatedUser($federatedUser);
		$member->setCircleId($circle->getSingleId());
		$member->setCircle($circle);
		$this->manageMemberStatus($circle, $member);

		$this->circleService->confirmCircleNotFull($circle);

		// TODO: Managing cached name
		//		$member->setCachedName($eventMember->getCachedName());

		$event->setOutcome($member->jsonSerialize());

		return;


//		$member = $this->membersRequest->getFreshNewMember(
//			$circle->getUniqueId(), $ident, $eventMember->getType(), $eventMember->getInstance()
//		);
//		$member->hasToBeInviteAble()
//
//		$this->membersService->addMemberBasedOnItsType($circle, $member);
//
//		$password = '';
//		$sendPasswordByMail = false;
//		if ($this->configService->enforcePasswordProtection($circle)) {
//			if ($circle->getSetting('password_single_enabled') === 'true') {
//				$password = $circle->getPasswordSingle();
//			} else {
//				$sendPasswordByMail = true;
//				$password = $this->miscService->token(15);
//			}
//		}
//
//		$event->setData(
//			new SimpleDataStore(
//				[
//					'password'       => $password,
//					'passwordByMail' => $sendPasswordByMail
//				]
//			)
//		);
	}


	/**
	 * @param FederatedEvent $event
	 *
	 * @throws InvalidIdException
	 */
	public function manage(FederatedEvent $event): void {
		$member = $event->getMember();

		try {
			$federatedUser = new FederatedUser();
			$federatedUser->importFromIFederatedUser($member);
			$this->federatedUserService->confirmLocalSingleId($federatedUser);
		} catch (FederatedUserException $e) {
			$this->e($e, ['member' => $member]);

			return;
		}

		$this->memberRequest->insertOrUpdate($member);

		$this->eventService->memberAdding($event);

//
//		//
//		// TODO: verifiez comment se passe le cached name sur un member_add
//		//
//		$cachedName = $member->getCachedName();
//		$password = $event->getData()
//						  ->g('password');
//
//		$shares = $this->generateUnknownSharesLinks($circle, $member, $password);
//		$result = [
//			'unknownShares' => $shares,
//			'cachedName'    => $cachedName
//		];
//
//		if ($member->getType() === DeprecatedMember::TYPE_CONTACT
//			&& $this->configService->isLocalInstance($member->getInstance())) {
//			$result['contact'] = $this->miscService->getInfosFromContact($member);
//		}
//
//		$event->setResult(new SimpleDataStore($result));
//		$this->eventsService->onMemberNew($circle, $member);
	}


	/**
	 * @param FederatedEvent $event
	 * @param array $results
	 */
	public function result(FederatedEvent $event, array $results): void {
		$this->eventService->memberAdded($event, $results);


//		$password = $cachedName = '';
//		$circle = $member = null;
//		$links = [];
//		$recipients = [];
//		foreach ($events as $event) {
//			$data = $event->getData();
//			if ($data->gBool('passwordByMail') !== false) {
//				$password = $data->g('password');
//			}
//			$circle = $event->getDeprecatedCircle();
//			$member = $event->getMember();
//			$result = $event->getResult();
//			if ($result->g('cachedName') !== '') {
//				$cachedName = $result->g('cachedName');
//			}
//
//			$links = array_merge($links, $result->gArray('unknownShares'));
//			$contact = $result->gArray('contact');
//			if (!empty($contact)) {
//				$recipients = $contact['emails'];
//			}
//		}
//
//		if (empty($links) || $circle === null || $member === null) {
//			return;
//		}
//
//		if ($cachedName !== '') {
//			$member->setCachedName($cachedName);
//			$this->membersService->updateMember($member);
//		}
//
//		if ($member->getType() === DeprecatedMember::TYPE_MAIL
//			|| $member->getType() === DeprecatedMember::TYPE_CONTACT) {
//			if ($member->getType() === DeprecatedMember::TYPE_MAIL) {
//				$recipients = [$member->getUserId()];
//			}
//
//			foreach ($recipients as $recipient) {
//				$this->memberIsMailbox($circle, $recipient, $links, $password);
//			}
//		}
	}


	/**
	 * @param Circle $circle
	 * @param Member $member
	 *
	 * @throws FederatedItemBadRequestException
	 */
	private function manageMemberStatus(Circle $circle, Member $member) {
		try {
			$knownMember = $this->memberRequest->searchMember($member);
			$member->setId($knownMember->getId());

			if ($knownMember->getLevel() === Member::LEVEL_NONE) {
				switch ($knownMember->getStatus()) {
					case Member::STATUS_BLOCKED:
						if ($circle->isConfig(Circle::CFG_INVITE)) {
							$member->setStatus(Member::STATUS_INVITED);
						}

						return;

					case Member::STATUS_REQUEST:
						$member->setLevel(Member::LEVEL_MEMBER);
						$member->setStatus(Member::STATUS_MEMBER);

						return;

					case Member::STATUS_INVITED:
						throw new FederatedItemBadRequestException(StatusCode::$MEMBER_ADD[123], 123);
				}
			}

			throw new FederatedItemBadRequestException(StatusCode::$MEMBER_ADD[122], 122);
		} catch (MemberNotFoundException $e) {

			$member->setId($this->uuid(ManagedModel::ID_LENGTH));

			if ($circle->isConfig(Circle::CFG_INVITE)) {
				$member->setStatus(Member::STATUS_INVITED);
			} else {
				$member->setLevel(Member::LEVEL_MEMBER);
				$member->setStatus(Member::STATUS_MEMBER);
			}
		}
	}


	/**
	 * confirm the validity of a UserId, based on UserType.
	 *
	 * @param IFederatedUser $member
	 *
	 * @throws FederatedUserException
	 * @throws InvalidIdException
	 * @throws UserTypeNotFoundException
	 * @throws CircleNotFoundException
	 * @throws FederatedUserNotFoundException
	 * @throws OwnerNotFoundException
	 * @throws RemoteInstanceException
	 * @throws RemoteNotFoundException
	 * @throws RemoteResourceNotFoundException
	 * @throws UnknownRemoteException
	 * @throws InvalidItemException
	 * @throws RequestNetworkException
	 * @throws SignatoryException
	 */
	private function confirmMember(IFederatedUser $member): void {

		// TODO: confirm SingleId ???
//		switch ($member->getUserType()) {
//			case Member::TYPE_USER:
		$this->federatedUserService->getFederatedUser($member->getUserId(), $member->getUserType());
//				break;
//
//			// TODO: confirm other UserType
//			default:
//				break;
////				throw new UserTypeNotFoundException();
//		}
	}


	/**
	 * @param IFederatedUser $member
	 *
	 * @throws NoUserException
	 */
	private function confirmMemberTypeUser(IFederatedUser $member): void {
		if ($this->configService->isLocalInstance($member->getInstance())) {
			$user = $this->userManager->get($member->getUserId());
			if ($user === null) {
				throw new NoUserException('user not found');
			}

			$member->setUserId($user->getUID());

			return;
		}

		// TODO #M002: request the remote instance and check that user exists
	}

//	/**
//	 * Verify if a local account is valid.
//	 *
//	 * @param $ident
//	 * @param $type
//	 *
//	 * @param string $instance
//	 *
//	 * @throws NoUserException
//	 */
//	private function verifyIdentLocalMember(&$ident, $type, string $instance = '') {
//		if ($type !== DeprecatedMember::TYPE_USER) {
//			return;
//		}
//
//		if ($instance === '') {
//			try {
//				$ident = $this->miscService->getRealUserId($ident);
//			} catch (NoUserException $e) {
//				throw new NoUserException($this->l10n->t("This user does not exist"));
//			}
//		}
//	}
//
//
//	/**
//	 * Verify if a mail have a valid format.
//	 *
//	 * @param string $ident
//	 * @param int $type
//	 *
//	 * @throws EmailAccountInvalidFormatException
//	 */
//	private function verifyIdentEmailAddress(string $ident, int $type) {
//		if ($type !== DeprecatedMember::TYPE_MAIL) {
//			return;
//		}
//
//		if ($this->configService->isAccountOnly()) {
//			throw new EmailAccountInvalidFormatException(
//				$this->l10n->t('You cannot add a mail address as member of your Circle')
//			);
//		}
//
//		if (!filter_var($ident, FILTER_VALIDATE_EMAIL)) {
//			throw new EmailAccountInvalidFormatException(
//				$this->l10n->t('Email format is not valid')
//			);
//		}
//	}
//
//
//	/**
//	 * Verify if a contact exist in current user address books.
//	 *
//	 * @param $ident
//	 * @param $type
//	 *
//	 * @throws NoUserException
//	 * @throws EmailAccountInvalidFormatException
//	 */
//	private function verifyIdentContact(&$ident, $type) {
//		if ($type !== DeprecatedMember::TYPE_CONTACT) {
//			return;
//		}
//
//		if ($this->configService->isAccountOnly()) {
//			throw new EmailAccountInvalidFormatException(
//				$this->l10n->t('You cannot add a contact as member of your Circle')
//			);
//		}
//
//		$tmpContact = $this->userId . ':' . $ident;
//		$result = MiscService::getContactData($tmpContact);
//		if (empty($result)) {
//			throw new NoUserException($this->l10n->t("This contact is not available"));
//		}
//
//		$ident = $tmpContact;
//	}


	/**
	 * @param DeprecatedCircle $circle
	 * @param string $recipient
	 * @param array $links
	 * @param string $password
	 */
	private function memberIsMailbox(
		DeprecatedCircle $circle, string $recipient, array $links, string $password
	) {
		if ($circle->getViewer() === null) {
			$author = $circle->getOwner()
							 ->getUserId();
		} else {
			$author = $circle->getViewer()
							 ->getUserId();
		}

		try {
			$template = $this->generateMailExitingShares($author, $circle->getName());
			$this->fillMailExistingShares($template, $links);
			$this->sendMailExistingShares($template, $author, $recipient);
			$this->sendPasswordExistingShares($author, $recipient, $password);
		} catch (Exception $e) {
			$this->miscService->log('Failed to send mail about existing share ' . $e->getMessage());
		}
	}


	/**
	 * @param DeprecatedCircle $circle
	 * @param DeprecatedMember $member
	 * @param string $password
	 *
	 * @return array
	 */
	private function generateUnknownSharesLinks(
		DeprecatedCircle $circle, DeprecatedMember $member, string $password
	): array {
		$unknownShares = $this->getUnknownShares($member);

		$data = [];
		foreach ($unknownShares as $share) {
			try {
				$data[] = $this->getMailLinkFromShare($share, $member, $password);
			} catch (TokenDoesNotExistException $e) {
			}
		}

		return $data;
	}


	/**
	 * @param DeprecatedMember $member
	 *
	 * @return array
	 */
	private function getUnknownShares(DeprecatedMember $member): array {
		$allShares = $this->fileSharesRequest->getSharesForCircle($member->getCircleId());
		$knownShares = array_map(
			function(SharesToken $shareToken) {
				return $shareToken->getShareId();
			},
			$this->tokensRequest->getTokensFromMember($member)
		);

		$unknownShares = [];
		foreach ($allShares as $share) {
			if (!in_array($share['id'], $knownShares)) {
				$unknownShares[] = $share;
			}
		}

		return $unknownShares;
	}


	/**
	 * @param array $share
	 * @param DeprecatedMember $member
	 * @param string $password
	 *
	 * @return array
	 * @throws TokenDoesNotExistException
	 */
	private function getMailLinkFromShare(array $share, DeprecatedMember $member, string $password = '') {
		$sharesToken = $this->tokensRequest->generateTokenForMember($member, (int)$share['id'], $password);
		$link = $this->urlGenerator->linkToRouteAbsolute(
			'files_sharing.sharecontroller.showShare',
			['token' => $sharesToken->getToken()]
		);
		$author = $share['uid_initiator'];
		$filename = basename($share['file_target']);

		return [
			'author'   => $author,
			'link'     => $link,
			'filename' => $filename
		];
	}


	/**
	 * @param string $author
	 * @param string $circleName
	 *
	 * @return IEMailTemplate
	 */
	private function generateMailExitingShares(string $author, string $circleName): IEMailTemplate {
		$emailTemplate = $this->mailer->createEMailTemplate('circles.ExistingShareNotification', []);
		$emailTemplate->addHeader();

		$text = $this->l10n->t('%s shared multiple files with \'%s\'.', [$author, $circleName]);
		$emailTemplate->addBodyText(htmlspecialchars($text), $text);

		return $emailTemplate;
	}

	/**
	 * @param IEMailTemplate $emailTemplate
	 * @param array $links
	 */
	private function fillMailExistingShares(IEMailTemplate $emailTemplate, array $links) {
		foreach ($links as $item) {
			$emailTemplate->addBodyButton(
				$this->l10n->t('Open »%s«', [htmlspecialchars($item['filename'])]), $item['link']
			);
		}
	}


	/**
	 * @param IEMailTemplate $emailTemplate
	 * @param string $author
	 * @param string $recipient
	 *
	 * @throws Exception
	 */
	private function sendMailExistingShares(IEMailTemplate $emailTemplate, string $author, string $recipient
	) {
		$subject = $this->l10n->t('%s shared multiple files with you.', [$author]);

		$instanceName = $this->defaults->getName();
		$senderName = $this->l10n->t('%s on %s', [$author, $instanceName]);

		$message = $this->mailer->createMessage();

		$message->setFrom([Util::getDefaultEmailAddress($instanceName) => $senderName]);
		$message->setSubject($subject);
		$message->setPlainBody($emailTemplate->renderText());
		$message->setHtmlBody($emailTemplate->renderHtml());
		$message->setTo([$recipient]);

		$this->mailer->send($message);
	}


	/**
	 * @param string $author
	 * @param string $email
	 * @param string $password
	 *
	 * @throws Exception
	 */
	protected function sendPasswordExistingShares(string $author, string $email, string $password) {
		if ($password === '') {
			return;
		}

		$message = $this->mailer->createMessage();

		$authorUser = $this->userManager->get($author);
		$authorName = ($authorUser instanceof IUser) ? $authorUser->getDisplayName() : $author;
		$authorEmail = ($authorUser instanceof IUser) ? $authorUser->getEMailAddress() : null;

		$this->miscService->log("Sending password mail about existing files to '" . $email . "'", 0);

		$plainBodyPart = $this->l10n->t(
			"%1\$s shared multiple files with you.\nYou should have already received a separate mail with a link to access them.\n",
			[$authorName]
		);
		$htmlBodyPart = $this->l10n->t(
			'%1$s shared multiple files with you. You should have already received a separate mail with a link to access them.',
			[$authorName]
		);

		$emailTemplate = $this->mailer->createEMailTemplate(
			'sharebymail.RecipientPasswordNotification', [
														   'password' => $password,
														   'author'   => $author
													   ]
		);

		$emailTemplate->setSubject(
			$this->l10n->t(
				'Password to access files shared to you by %1$s', [$authorName]
			)
		);
		$emailTemplate->addHeader();
		$emailTemplate->addHeading($this->l10n->t('Password to access files'), false);
		$emailTemplate->addBodyText(htmlspecialchars($htmlBodyPart), $plainBodyPart);
		$emailTemplate->addBodyText($this->l10n->t('It is protected with the following password:'));
		$emailTemplate->addBodyText($password);

		// The "From" contains the sharers name
		$instanceName = $this->defaults->getName();
		$senderName = $this->l10n->t(
			'%1$s via %2$s',
			[
				$authorName,
				$instanceName
			]
		);

		$message->setFrom([\OCP\Util::getDefaultEmailAddress($instanceName) => $senderName]);
		if ($authorEmail !== null) {
			$message->setReplyTo([$authorEmail => $authorName]);
			$emailTemplate->addFooter($instanceName . ' - ' . $this->defaults->getSlogan());
		} else {
			$emailTemplate->addFooter();
		}

		$message->setTo([$email]);
		$message->useTemplate($emailTemplate);
		$this->mailer->send($message);
	}

}

