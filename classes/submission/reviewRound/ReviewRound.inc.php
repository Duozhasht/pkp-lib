<?php
/**
 * @defgroup submission_reviewRound Review Round
 */

/**
 * @file classes/submission/reviewRound/ReviewRound.inc.php
 *
 * Copyright (c) 2014-2017 Simon Fraser University
 * Copyright (c) 2003-2017 John Willinsky
 * Distributed under the GNU GPL v2. For full terms see the file docs/COPYING.
 *
 * @class ReviewRound
 * @ingroup submission_reviewRound
 * @see ReviewRoundDAO
 *
 * @brief Basic class describing a review round.
 */

// The first four statuses are set explicitly by EditorDecisions, which override
// the current status.
define('REVIEW_ROUND_STATUS_REVISIONS_REQUESTED', 1);
define('REVIEW_ROUND_STATUS_RESUBMITTED', 2);
define('REVIEW_ROUND_STATUS_SENT_TO_EXTERNAL', 3);
define('REVIEW_ROUND_STATUS_ACCEPTED', 4);
define('REVIEW_ROUND_STATUS_DECLINED', 5);

// The following statuses are calculated based on the statuses of ReviewAssignments
// in this round.
define('REVIEW_ROUND_STATUS_PENDING_REVIEWERS', 6); // No reviewers have been assigned
define('REVIEW_ROUND_STATUS_PENDING_REVIEWS', 7); // Waiting for reviews to be submitted by reviewers
define('REVIEW_ROUND_STATUS_REVIEWS_READY', 8); // One or more reviews is ready for an editor to view
define('REVIEW_ROUND_STATUS_REVIEWS_COMPLETED', 9); // All assigned reviews have been confirmed by an editor
define('REVIEW_ROUND_STATUS_REVIEWS_OVERDUE', 10); // One or more reviews is overdue

// The following status is calculated when the round is in revisions and at
// at least one revision file has been uploaded.
define('REVIEW_ROUND_STATUS_REVISIONS_SUBMITTED', 11);

class ReviewRound extends DataObject {

	//
	// Get/set methods
	//

	/**
	 * get submission id
	 * @return int
	 */
	function getSubmissionId() {
		return $this->getData('submissionId');
	}

	/**
	 * set submission id
	 * @param $submissionId int
	 */
	function setSubmissionId($submissionId) {
		$this->setData('submissionId', $submissionId);
	}

	/**
	 * Get review stage id (internal or external review).
	 * @return int
	 */
	function getStageId() {
		return $this->getData('stageId');
	}

	/**
	 * Set review stage id
	 * @param $stageId int
	 */
	function setStageId($stageId) {
		$this->setData('stageId', $stageId);
	}

	/**
	 * Get review round
	 * @return int
	 */
	function getRound() {
		return $this->getData('round');
	}

	/**
	 * Set review round
	 * @param $assocType int
	 */
	function setRound($round) {
		$this->setData('round', $round);
	}

	/**
	 * Get current round status
	 * @return int
	 */
	function getStatus() {
		return $this->getData('status');
	}

	/**
	 * Set current round status
	 * @param $status int
	 */
	function setStatus($status) {
		$this->setData('status', $status);
	}

	/**
	 * Calculate the status of this review round.
	 *
	 * If the round is in revisions, it will search for revision files and set
	 * the status accordingly. If the round has not reached a revision status
	 * yet, it will determine the status based on the statuses of the round's
	 * ReviewAssignments.
	 *
	 * @return int
	 */
	public function determineStatus() {

		// Check if revisions requested or received, if this is latest review round and then check files
		$roundStatus = $this->getStatus();

		// If revisions have been requested, check to see if any have been
		// submitted
		if ($this->getStatus() == REVIEW_ROUND_STATUS_REVISIONS_REQUESTED || $this->getStatus() == REVIEW_ROUND_STATUS_REVISIONS_SUBMITTED) {
			$submissionFileDao = DAORegistry::getDAO('SubmissionFileDAO');
			$submissionFiles = $submissionFileDao->getRevisionsByReviewRound($this, SUBMISSION_FILE_REVIEW_REVISION);
			if (empty($submissionFiles)) {
				return REVIEW_ROUND_STATUS_REVISIONS_REQUESTED;
			}
			return REVIEW_ROUND_STATUS_REVISIONS_SUBMITTED;
		}

		// Leave the status alone if it is set to one of the EditorDecisions
		// which have advanced the submission beyond this round
		$statusFinished = in_array(
			$this->getStatus(),
			array(
				REVIEW_ROUND_STATUS_RESUBMITTED,
				REVIEW_ROUND_STATUS_SENT_TO_EXTERNAL,
				REVIEW_ROUND_STATUS_ACCEPTED,
				REVIEW_ROUND_STATUS_DECLINED
			)
		);
		if ($statusFinished) {
			return $this->getStatus();
		}

		// Determine the round status by looking at the assignment statuses
		$anyOverdueReview = false;
		$anyIncompletedReview = false;
		$anyUnreadReview = false;
		import('lib.pkp.classes.submission.reviewAssignment.ReviewAssignmentDAO');
		$reviewAssignmentDao = DAORegistry::getDAO('ReviewAssignmentDAO');
		$reviewAssignments = $reviewAssignmentDao->getByReviewRoundId($this->getId());
		foreach ($reviewAssignments as $reviewAssignment) {
			assert(is_a($reviewAssignment, 'ReviewAssignment'));

			$assignmentStatus = $reviewAssignment->getStatus();

			switch ($assignmentStatus) {
				case REVIEW_ASSIGNMENT_STATUS_DECLINED:
					break;

				case REVIEW_ASSIGNMENT_STATUS_RESPONSE_OVERDUE:
				case REVIEW_ASSIGNMENT_STATUS_REVIEW_OVERDUE:
					$anyOverdueReview = true;
					break;

				case REVIEW_ASSIGNMENT_STATUS_AWAITING_RESPONSE:
				case REVIEW_ASSIGNMENT_STATUS_ACCEPTED:
					$anyIncompletedReview = true;
					break;

				case REVIEW_ASSIGNMENT_STATUS_RECEIVED:
					$anyUnreadReview = true;
					break;
			}
		}

		// Find the correct review round status based on the state of
		// the current review assignments. The check order matters: the
		// first conditions override the others.
		if (empty($reviewAssignments)) {
			return REVIEW_ROUND_STATUS_PENDING_REVIEWERS;
		} elseif ($anyOverdueReview) {
			return REVIEW_ROUND_STATUS_REVIEWS_OVERDUE;
		} elseif ($anyUnreadReview) {
			return REVIEW_ROUND_STATUS_REVIEWS_READY;
		} elseif ($anyIncompletedReview) {
			return REVIEW_ROUND_STATUS_PENDING_REVIEWS;
		}
		return REVIEW_ROUND_STATUS_REVIEWS_COMPLETED;
	}

	/**
	 * Get locale key associated with current status
	 * @param $isAuthor boolean True iff the status is to be shown to the author (slightly tweaked phrasing)
	 * @return int
	 */
	function getStatusKey($isAuthor = false) {
		switch ($this->getStatus()) {
			case REVIEW_ROUND_STATUS_REVISIONS_REQUESTED:
				return 'editor.submission.roundStatus.revisionsRequested';
			case REVIEW_ROUND_STATUS_REVISIONS_SUBMITTED:
				return 'editor.submission.roundStatus.revisionsSubmitted';
			case REVIEW_ROUND_STATUS_RESUBMITTED:
				return 'editor.submission.roundStatus.resubmitted';
			case REVIEW_ROUND_STATUS_SENT_TO_EXTERNAL:
				return 'editor.submission.roundStatus.sentToExternal';
			case REVIEW_ROUND_STATUS_ACCEPTED:
				return 'editor.submission.roundStatus.accepted';
			case REVIEW_ROUND_STATUS_DECLINED:
				return 'editor.submission.roundStatus.declined';
			case REVIEW_ROUND_STATUS_PENDING_REVIEWERS:
				return 'editor.submission.roundStatus.pendingReviewers';
			case REVIEW_ROUND_STATUS_PENDING_REVIEWS:
				return 'editor.submission.roundStatus.pendingReviews';
			case REVIEW_ROUND_STATUS_REVIEWS_READY:
				return $isAuthor?'author.submission.roundStatus.reviewsReady':'editor.submission.roundStatus.reviewsReady';
			case REVIEW_ROUND_STATUS_REVIEWS_COMPLETED:
				return 'editor.submission.roundStatus.reviewsCompleted';
			case REVIEW_ROUND_STATUS_REVIEWS_OVERDUE:
				return 'editor.submission.roundStatus.reviewOverdue';
			default: return null;
		}
	}
}

?>
