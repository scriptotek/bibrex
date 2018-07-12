<?php

namespace App\Http\Controllers;

use App\Events\LoanTableUpdated;
use App\Http\Requests\CheckoutRequest;
use App\Item;
use App\Loan;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

class LoansController extends Controller
{
    /**
     * Validation error messages.
     *
     * @static array
     */
    protected $messages = [
        'user.required' => 'Trenger enten navn eller låne-ID.',
        'user.id.required_without' => 'Trenger enten navn eller låne-ID.',
        'user.name.required_without' => 'Trenger enten navn eller låne-ID.',
        'thing.required' => 'Uten ting blir det bare ingenting.',
    ];

    /*
     * Factory for Laravel Auth
     */
    protected $auth;

    /*
     * The currently logged in library
     */
    protected $library;

    /**
     * Display a listing of the resource.
     *
     * @param Request $request
     * @return Response
     */
    public function getIndex(Request $request)
    {
        $user = $request->input('user')
            ? ['name' => $request->input('user')]
            : $request->session()->get('user');

        $thing = $request->input('thing')
            ? ['name' => $request->input('thing')]
            : $request->session()->get('thing');

        return response()->view('loans.index', [
            'library_id' => \Auth::user()->id,
            'user' => $user,
            'thing' => $thing,
        ])->header('Cache-Control', 'private, no-store, no-cache, must-revalidate, max-age=0');
    }

    /**
     * Display a listing of the resource.
     *
     * @param Request $request
     * @return Response
     */
    public function json()
    {
        $library = \Auth::user();

        $loans = Loan::with('item.thing', 'user', 'notifications')
            ->where('library_id', $library->id)
            ->orderBy('created_at', 'desc')->get();

        return response()->json($loans);
    }

    /**
     * Display the specified resource.
     *
     * @param Loan $loan
     * @return Response
     */
    public function getShow(Loan $loan)
    {
        if ($loan) {
            return response()->view('loans.show', array('loan' => $loan));
        } else {
            return response()->view('errors.404', array('what' => 'Lånet'), 404);
        }
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param CheckoutRequest $request
     * @return Response
     */
    public function checkout(CheckoutRequest $request)
    {
        // Create new loan
        $loan = new Loan();
        $loan->user_id = $request->user->id;
        $loan->item_id = $request->item->id;
        $loan->due_at = Carbon::now()
            ->addDays($request->item->thing->properties->loan_time)
            ->setTime(0, 0, 0);
        $loan->as_guest = false;
        if (!$loan->save()) {
            return response()->json(['errors' => $loan->errors], 409);
        }

        $request->user->loan_count += 1;
        $request->user->last_loan_at = Carbon::now();
        $request->user->save();

        \Log::info(sprintf(
            'Lånte ut %s (<a href="%s">Detaljer</a>).',
            $request->item->thing->properties->get('name_indefinite.nob'),
            action('LoansController@getShow', $loan->id)
        ));

        event(new LoanTableUpdated('checkout', $request, $loan));

        $loan->load('user', 'item', 'item.thing');

        // getSuccessMsg(loan) {
        //     let msg = `Utlån av ${loan.item.thing.properties.name_indefinite.nob} til ${loan.user.name} registrert`;

        //     switch (Math.floor(Math.random() * 20)) {
        //         case 0:
        //             msg += ' (og verden har forøvrig ikke gått under)';
        //             break;
        //         case 1:
        //             msg += ' (faktisk helt sant)';
        //             break;
        //     }

        //     msg += `. Lånetid: ${loan.days_left} ${loan.days_left == 1 ? 'dag' : 'dager'}.`;
        //     return msg;
        // },

        return response()->json([
            'status' => 'Utlånet ble registrert.',
            'loan' => $loan,
        ]);
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param Loan $loan
     * @return Response
     */
    public function edit(Loan $loan)
    {
        return response()->view('loans.edit', ['loan' => $loan]);
    }

    /**
     * Update the specified resource in storage.
     *
     * @param Loan $loan
     * @param Request $request
     * @return void
     */
    public function update(Loan $loan, Request $request)
    {
        $request->validate([
            'due_at' => 'required|date',
        ]);

        $old_date = $loan->due_at;

        $loan->due_at = Carbon::parse($request->due_at);
        $loan->note = $request->note;
        $loan->save();

        \Log::info(sprintf(
            'Endret forfallsdato for <a href="%s">utlånet</a> av %s fra %s til %s.',
            action('LoansController@getShow', $loan->id),
            $loan->item->thing->properties->get('name_indefinite.nob'),
            $old_date->toDateString(),
            $loan->due_at->toDateString()
        ));
        event(new LoanTableUpdated('update', $request, $loan));

        return redirect()->action('LoansController@getIndex')
            ->with('status', 'Lånet ble oppdatert');
    }

    /**
     * Mark the specified resource as lost.
     *
     * @param Loan $loan
     * @param Request $request
     * @return Response
     */
    public function lost(Loan $loan, Request $request)
    {
        \Log::info('Registrerte ' . $loan->item->thing->properties->get('name_indefinite.nob') . ' som tapt' .
            ' (<a href="'. action('LoansController@getShow', $loan->id) . '">Detaljer</a>)');

        $loan->lost();

        event(new LoanTableUpdated('lost', $request, $loan));

        return response()->json([
            'status' => sprintf('%s ble registrert som tapt.', $loan->item->formattedLink(true)),
            'undoLink' => action('LoansController@restore', $loan->id),
        ]);
    }

    /**
     * Checkin the specified loan.
     *
     * @param Request $request
     * @return Response
     */
    public function checkin(Request $request)
    {
        $status = null;
        $undoLink = null;
        if ($request->input('barcode')) {
            $loan = Loan::with(['item', 'item.thing', 'user'])
                ->whereHas('item', function ($query) use ($request) {
                    $query->where('barcode', '=', $request->input('barcode'));
                })
                ->first();
        } elseif ($request->input('loan')) {
            $loan = Loan::with(['item', 'item.thing', 'user'])
                ->find($request->input('loan'));
        } else {
            return response()->json([
                'status' => 'Ingenting har blitt retunert. Det kan argumenteres for at dette ' .
                    'var en unødvendig operasjon, men hvem vet.',
            ], 200);
        }

        if (is_null($loan)) {
            $loan = Loan::with(['item', 'item.thing', 'user'])
                ->withTrashed()
                ->whereHas('item', function ($query) use ($request) {
                    $query->where('barcode', '=', $request->input('barcode'));
                })
                ->orderBy('updated_at', 'desc')
                ->first();
        }

        if (is_null($loan)) {
            $item = Item::withTrashed()->where('barcode', '=', $request->input('barcode'))->first();
            if ($item) {
                return response()->json([
                    'error' => sprintf(
                        'Denne %s var ikke utlånt.',
                        $item->formattedLink(false)
                    )
                ], 422);
            }
            return response()->json([
                'error' => 'Bibrex kan ikke huske å ha sett strekkoden «' . $request->input('barcode') . '» før. ' .
                    'Er den registrert?',
            ], 422);
        }

        if ($loan->is_lost) {
            $status = sprintf(
                'Denne %s var registrert som tapt, men ikke nå lenger (takket være deg)!',
                $loan->item->formattedLink(false)
            );
            $loan->found();
        } elseif ($loan->item->trashed()) {
            $status = sprintf(
                'Du store min hatt, denne %s har faktisk blitt kassert i mellomtiden!',
                $loan->item->formattedLink(false)
            );
        } elseif ($loan->trashed()) {
            $status = sprintf(
                'Denne %s var strengt tatt ikke utlånt (men det går helt greit).',
                $loan->item->formattedLink(false)
            );
        } else {
            $status = sprintf('%s ble returnert.', $loan->item->formattedLink(true));
            $undoLink = action('LoansController@restore', $loan->id);
        }

        \Log::info(sprintf(
            'Returnerte %s (<a href="%s">Detaljer</a>).',
            $loan->item->thing->properties->get('name_indefinite.nob'),
            action('LoansController@getShow', $loan->id)
        ));

        $user = $loan->user;

        $loan->checkIn();

        $user->last_loan_at = Carbon::now();
        $user->save();

        event(new LoanTableUpdated('checkin', $request, $loan));

        return response()->json([
            'status' => $status,
            'undoLink' => $undoLink,
        ]);
    }

    /**
     * Restores the specified loan.
     *
     * @param Loan $loan
     * @param Request $request
     * @return Response
     */
    public function restore(Loan $loan, Request $request)
    {
        \Log::info(sprintf(
            'Angret retur av %s (<a href="%s">Detaljer</a>).',
            $loan->item->thing->properties->get('name_indefinite.nob'),
            action('LoansController@getShow', $loan->id)
        ));

        if ($loan->is_lost) {
            $loan->found();
        } else {
            $loan->restore();
        }

        event(new LoanTableUpdated('restore', $request, $loan));

        return response()->json([
            'status' => sprintf(
                'Angret. %s er fortsatt utlånt til %s.',
                $loan->item->formattedLink(true),
                $loan->user->name
            ),
        ]);
    }
}
