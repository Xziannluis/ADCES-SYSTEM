import inspect
import app

print('app module file:', inspect.getfile(app))
# Print route paths in a stable way
try:
    routes = [getattr(r, 'path', None) for r in getattr(app, 'app').routes]
    print('routes:', sorted([r for r in routes if r]))
except Exception as e:
    print('could not list routes:', e)
