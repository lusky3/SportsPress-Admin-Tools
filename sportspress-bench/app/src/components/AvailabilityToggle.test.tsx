// @vitest-environment jsdom
import { act, cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { ApiError } from '../api';
import { AvailabilityToggle } from './AvailabilityToggle';

afterEach( cleanup );

function checked(): string | null {
	const radio = screen.getAllByRole( 'radio' ).find( ( r ) => r.getAttribute( 'aria-checked' ) === 'true' );
	return radio ? radio.textContent : null;
}

describe( 'AvailabilityToggle', () => {
	it( 'is a radiogroup of In, Out and Maybe', () => {
		render( <AvailabilityToggle value="in" onChange={ vi.fn() } /> );
		expect( screen.getByRole( 'radiogroup' ) ).toBeTruthy();
		expect( screen.getAllByRole( 'radio' ).map( ( r ) => r.textContent ) ).toEqual( [ 'In', 'Out', 'Maybe' ] );
		expect( checked() ).toBe( 'In' );
	} );

	it( 'calls onChange with out on click', async () => {
		const onChange = vi.fn().mockResolvedValue( undefined );
		render( <AvailabilityToggle value="in" onChange={ onChange } /> );
		await act( async () => {
			fireEvent.click( screen.getByRole( 'radio', { name: 'Out' } ) );
		} );
		expect( onChange ).toHaveBeenCalledWith( 'out' );
		expect( checked() ).toBe( 'Out' );
	} );

	it( 'moves to Out with ArrowRight from In and focuses it', async () => {
		const onChange = vi.fn().mockResolvedValue( undefined );
		render( <AvailabilityToggle value="in" onChange={ onChange } /> );
		const inRadio = screen.getByRole( 'radio', { name: 'In' } );
		inRadio.focus();
		await act( async () => {
			fireEvent.keyDown( inRadio, { key: 'ArrowRight' } );
		} );
		expect( onChange ).toHaveBeenCalledWith( 'out' );
		expect( document.activeElement ).toBe( screen.getByRole( 'radio', { name: 'Out' } ) );
	} );

	it( 'wraps from Maybe to In with ArrowRight and from In to Maybe with ArrowLeft', async () => {
		const onChange = vi.fn().mockResolvedValue( undefined );
		render( <AvailabilityToggle value="maybe" onChange={ onChange } /> );
		await act( async () => {
			fireEvent.keyDown( screen.getByRole( 'radio', { name: 'Maybe' } ), { key: 'ArrowRight' } );
		} );
		expect( onChange ).toHaveBeenLastCalledWith( 'in' );
		await act( async () => {
			fireEvent.keyDown( screen.getByRole( 'radio', { name: 'In' } ), { key: 'ArrowLeft' } );
		} );
		expect( onChange ).toHaveBeenLastCalledWith( 'maybe' );
	} );

	it( 'keeps only the checked radio in the tab order, or the first when nothing is answered', () => {
		const { rerender } = render( <AvailabilityToggle value="maybe" onChange={ vi.fn() } /> );
		expect( screen.getAllByRole( 'radio' ).map( ( r ) => r.tabIndex ) ).toEqual( [ -1, -1, 0 ] );
		rerender( <AvailabilityToggle value={ null } onChange={ vi.fn() } /> );
		expect( screen.getAllByRole( 'radio' ).map( ( r ) => r.tabIndex ) ).toEqual( [ 0, -1, -1 ] );
		expect( checked() ).toBeNull();
	} );

	it( 'rolls back to the previous value and shows the error when the save fails', async () => {
		let reject: ( e: unknown ) => void = () => {};
		const onChange = vi.fn( () => new Promise<void>( ( _, r ) => ( reject = r ) ) );
		render( <AvailabilityToggle value="in" onChange={ onChange } /> );
		await act( async () => {
			fireEvent.click( screen.getByRole( 'radio', { name: 'Out' } ) );
		} );
		expect( checked() ).toBe( 'Out' ); // optimistic
		await act( async () => {
			reject( new ApiError( 0, 'offline', 'No connection.' ) );
		} );
		expect( checked() ).toBe( 'In' );
		expect( screen.getByRole( 'alert' ).textContent ).toMatch( /didn.t save/i );
	} );

	it( 'says why when the game is locked', async () => {
		const onChange = vi.fn().mockRejectedValue( new ApiError( 409, 'spbn_locked', 'This game is over; answers are locked.' ) );
		render( <AvailabilityToggle value={ null } onChange={ onChange } /> );
		await act( async () => {
			fireEvent.click( screen.getByRole( 'radio', { name: 'In' } ) );
		} );
		expect( checked() ).toBeNull();
		expect( screen.getByRole( 'alert' ).textContent ).toMatch( /locked/i );
	} );

	it( 'does nothing when disabled', async () => {
		const onChange = vi.fn();
		render( <AvailabilityToggle value="in" onChange={ onChange } disabled /> );
		await act( async () => {
			fireEvent.click( screen.getByRole( 'radio', { name: 'Out' } ) );
		} );
		expect( onChange ).not.toHaveBeenCalled();
		expect( checked() ).toBe( 'In' );
	} );
} );
