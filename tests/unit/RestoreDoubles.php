<?php
// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- The doubles belong together and are only used by the restore step tests.
// phpcs:disable WordPress.WP.AlternativeFunctions -- Test doubles that work on real files without WordPress loaded.
namespace OpenVault\Backup\Tests\Unit;

use OpenVault\Backup\Destinations\DestinationException;
use OpenVault\Backup\Destinations\DestinationInterface;
use OpenVault\Backup\Destinations\RemotePath;
use OpenVault\Backup\Destinations\ResumableDownloadInterface;
use OpenVault\Backup\Destinations\TestResult;
use OpenVault\Backup\Engine\Budget;
use OpenVault\Backup\Engine\JobContext;
use OpenVault\Backup\Engine\JobState;
use OpenVault\Backup\Engine\StepResult;
use OpenVault\Backup\Engine\Steps\StepInterface;
use OpenVault\Backup\Restore\PlanLoader;
use OpenVault\Backup\Restore\RestorePlan;
use OpenVault\Backup\Support\Logger;

/**
 * A logger that remembers its lines in memory.
 */
class MemoryLogger extends Logger {

	public $lines = array();

	public function __construct() {
	}

	public function log( $level, $message, array $context = array(), $backup_id = null ) {
		$this->lines[] = array( $level, $message, $context );
		return true;
	}
}

/**
 * A plan loader that returns a prepared plan (and a local folder).
 */
class FakePlanLoader extends PlanLoader {

	public $plan;
	public $folder;
	public $plans = array();
	public $calls = 0;

	public function __construct( $plan = null, $folder = null ) {
		$this->plan   = $plan;
		$this->folder = $folder;
	}

	public function load( array $backup, $mode, array $tried = array(), $work_dir = null ) {
		++$this->calls;
		// A test may register a plan per tried-list (key: comma list of tried sources).
		$key  = implode( ',', $tried );
		$plan = isset( $this->plans[ $key ] ) ? $this->plans[ $key ] : $this->plan;
		return array(
			'plan'     => $plan,
			'manifest' => null,
			'error'    => $plan instanceof RestorePlan ? '' : 'No plan.',
			'newer'    => false,
		);
	}

	public function local_folder( array $backup ) {
		return $this->folder;
	}
}

/**
 * A destination that serves files from memory, in small chunks. It does not support offsets (like a provider without
 * ResumableDownloadInterface).
 */
class FakeDownloads implements DestinationInterface {

	public $files   = array();
	public $chunk   = 1000;
	public $calls   = array();
	public $fail    = array();
	public $cut     = array();
	public $garbage = false;

	public function id() {
		return 'fake';
	}

	public function label() {
		return 'Fake';
	}

	public function test_connection() {
		return TestResult::success( 'ok' );
	}

	public function begin_upload( $remote_path, $size, array $options = array() ) {
		return array();
	}

	public function upload_chunk( array $session, $local_file, Budget $budget ) {
		throw DestinationException::permanent( 'No.' );
	}

	public function abort_upload( array $session ) {
	}

	public function download( $remote_ref, callable $sink ) {
		$this->serve( $remote_ref, 0, $sink );
	}

	public function delete( $remote_ref ) {
	}

	public function list( $remote_prefix = '', $limit = 10000 ) {
		return array();
	}

	public function quota() {
		return null;
	}

	public function remote_root() {
		return RemotePath::ROOT;
	}

	protected function serve( $remote_ref, $offset, callable $sink ) {
		$this->calls[] = array( basename( $remote_ref ), $offset );
		if ( isset( $this->fail[ basename( $remote_ref ) ] ) && count( $this->fail[ basename( $remote_ref ) ] ) > 0 ) {
			$error = array_shift( $this->fail[ basename( $remote_ref ) ] );
			throw $error;
		}
		if ( ! isset( $this->files[ $remote_ref ] ) ) {
			throw DestinationException::permanent( 'The file does not exist.' );
		}
		$data = $this->files[ $remote_ref ];
		if ( isset( $this->cut[ basename( $remote_ref ) ] ) ) {
			$data = substr( $data, 0, $this->cut[ basename( $remote_ref ) ] );
			unset( $this->cut[ basename( $remote_ref ) ] );
		}
		$length = strlen( $data );
		for ( $at = $offset; $at < $length; $at += $this->chunk ) {
			if ( false === call_user_func( $sink, substr( $data, $at, $this->chunk ) ) ) {
				return;
			}
		}
	}
}

/**
 * The same, with offsets.
 */
class FakeResumableDownloads extends FakeDownloads implements ResumableDownloadInterface {

	public function download( $remote_ref, callable $sink ) {
		$this->download_from( $remote_ref, 0, $sink );
	}

	public function download_from( $remote_ref, $offset, callable $sink ) {
		$this->serve( $remote_ref, (int) $offset, $sink );
	}
}

/**
 * Runs a step the way the restore runner does: slice after slice, each with a fresh budget, following the results.
 */
final class StepDriver {

	/**
	 * A budget that allows about $reads clock reads (roughly that many chunks).
	 *
	 * @param int $reads Reads.
	 * @return Budget
	 */
	public static function budget( $reads ) {
		$clock = 0.0;
		return new Budget(
			max( 1, (int) $reads ),
			PHP_INT_MAX,
			static function () use ( &$clock ) {
				$clock += 1.0;
				return $clock;
			}
		);
	}

	/**
	 * Drive a step until it ends.
	 *
	 * @param StepInterface       $step      Step.
	 * @param JobState            $state     Start state.
	 * @param array<string,mixed> $backup    Backup row.
	 * @param string              $work_dir  Work folder.
	 * @param int                 $reads     Budget per slice.
	 * @param MemoryLogger        $logger    Logger.
	 * @param int                 $max       Most slices.
	 * @return array{kind:string,state:JobState,message:string,slices:int,kinds:string[],max_cursor:int}
	 */
	public static function drive( StepInterface $step, JobState $state, array $backup, $work_dir, $reads, MemoryLogger $logger, $max = 500 ) {
		$kinds      = array();
		$max_cursor = 0;
		for ( $slice = 1; $slice <= $max; $slice++ ) {
			$result  = $step->run( new JobContext( $backup, $state, self::budget( $reads ), $logger, $work_dir, array() ) );
			$kinds[] = $result->kind();
			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- WordPress is not loaded in unit tests.
			$max_cursor = max( $max_cursor, strlen( json_encode( $result->state()->get_cursor() ) ) );
			if ( StepResult::KIND_NEXT === $result->kind() || StepResult::KIND_FAIL === $result->kind() ) {
				return array(
					'kind'       => $result->kind(),
					'state'      => $result->state(),
					'message'    => $result->message(),
					'slices'     => $slice,
					'kinds'      => $kinds,
					'max_cursor' => $max_cursor,
				);
			}
			$state = $result->state();
		}
		throw new \RuntimeException( 'The step did not finish.' );
	}
}
