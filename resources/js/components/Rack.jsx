export default function Rack({ locations, active, onPick }) {
    const bins = locations.filter((location) => location.zone === 'S1' && location.rack && location.shelf);
    const racks = bins.length
        ? [...new Set(bins.map((location) => location.rack))].sort()
        : ['A', 'B', 'C', 'D', 'E', 'F'];
    const shelves = bins.length
        ? [...new Set(bins.map((location) => String(location.shelf)))].sort((left, right) => Number(right) - Number(left) || right.localeCompare(left))
        : ['6', '5', '4', '3', '2', '1'];
    const byCode = Object.fromEntries(locations.map((location) => [location.code, location]));
    const columns = { gridTemplateColumns: `28px repeat(${racks.length}, minmax(4.5rem, 1fr))` };

    return (
        <div className="rack" dir="ltr">
            {shelves.map((shelf) => (
                <div className="rack-row" key={shelf} style={columns}>
                    <span className="rack-idx">{shelf}</span>
                    {racks.map((rack) => {
                        const code = `S1${rack}${shelf}`;
                        const cell = byCode[code];
                        const qty = cell?.on_hand || 0;

                        return (
                            <button
                                key={code}
                                type="button"
                                className={active === code ? 'cell on' : 'cell'}
                                data-hot={qty > 0 ? '1' : '0'}
                                aria-pressed={active === code}
                                onClick={() => onPick(active === code ? '' : code)}
                            >
                                <b>{code}</b>
                                <small>{qty || '—'}</small>
                            </button>
                        );
                    })}
                </div>
            ))}
            <div className="rack-cols" style={columns}>
                <span />
                {racks.map((rack) => <span key={rack}>{rack}</span>)}
            </div>
        </div>
    );
}
